<?php
// Advanced installer for OnlineBank
// WARNING: This script is intended for initial setup. Remove the installer directory after use.

$projectRoot = dirname(__DIR__);
$envExamplePath = $projectRoot . DIRECTORY_SEPARATOR . '.env.example';
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';

function read_env_example($path){
    if(!file_exists($path)) return null;
    return file_get_contents($path);
}

function write_env($path, $content){
    return file_put_contents($path, $content) !== false;
}

function test_db($host, $port, $db, $user, $pass){
    $mysqli = @new mysqli($host, $user, $pass, $db, (int)$port);
    if($mysqli && $mysqli->connect_errno === 0){
        $mysqli->close();
        return [true, 'Connection successful'];
    }
    return [false, $mysqli ? $mysqli->connect_error : 'Unknown error'];
}

function run_command($cmd){
    $out = null; $rc = null;
    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $process = @proc_open($cmd, $descriptors, $pipes, null, null);
    if(!is_resource($process)) return ['success' => false, 'output' => 'Cannot execute command: ' . $cmd];
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc = proc_close($process);
    return ['success' => $rc === 0, 'output' => trim($stdout), 'error' => trim($stderr), 'rc' => $rc];
}

function verify_envato_purchase($code, $token){
    if(!function_exists('curl_init')){
        return [false, 'cURL is not available on this server.'];
    }
    $url = 'https://api.envato.com/v3/market/author/sale?code=' . urlencode($code);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: OnlineBank Installer',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if($res === false){
        return [false, 'cURL error: ' . $err];
    }
    $data = json_decode($res, true);
    if($http === 200 && is_array($data)){
        return [true, $data];
    }
    $msg = is_array($data) && isset($data['message']) ? $data['message'] : $res;
    return [false, 'Envato API error (HTTP ' . $http . '): ' . $msg];
}

function env_set_key($envPath, $key, $value){
    if(!file_exists($envPath)) return false;
    $content = file_get_contents($envPath);
    if(preg_match('/^' . preg_quote($key, '/') . '\s*=.*$/m', $content)){
        $content = preg_replace('/^' . preg_quote($key, '/') . '\s*=.*$/m', $key . '=' . $value, $content);
    }else{
        $content .= "\n" . $key . '=' . $value;
    }
    return file_put_contents($envPath, $content) !== false;
}

$example = read_env_example($envExamplePath);

$messages = [];
$errors = [];
$step = 'form';

// AJAX handler: license verification against a verification server
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_license'){
    header('Content-Type: application/json');
    $purchase_code = trim($_POST['purchase_code'] ?? '');
    $license_server_url = trim($_POST['license_server_url'] ?? '');
    if(!$purchase_code){
        echo json_encode(['success' => false, 'message' => 'Please provide a purchase code.']);
        exit;
    }
    if(!$license_server_url){
        echo json_encode(['success' => false, 'message' => 'No license server URL provided.']);
        exit;
    }
    if(!function_exists('curl_init')){
        echo json_encode(['success' => false, 'message' => 'cURL is not available on this server.']);
        exit;
    }
    $payload = json_encode(['purchase_code' => $purchase_code]);
    $ch = curl_init($license_server_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: OnlineBank Installer'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if($res === false){
        echo json_encode(['success'=>false,'message'=>'License server request failed: '.$err]);
        exit;
    }
    $data = json_decode($res, true);
    if($http === 200 && is_array($data) && !empty($data['valid'])){
        echo json_encode(['success'=>true,'message'=>'License verified','data'=>$data]);
        exit;
    }
    $msg = is_array($data) && isset($data['message']) ? $data['message'] : ($res ?: 'Verification failed');
    echo json_encode(['success'=>false,'message'=>'Verification failed: '.$msg,'http'=>$http,'raw'=>$data]);
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    // Basic CSRF-like check: confirm checkbox
    $confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'on';
    if(!$confirm){
        $errors[] = 'Please confirm you understand the installer will create/overwrite a .env file and may run migrations.';
    }

    $app_url = trim($_POST['APP_URL'] ?? '');
    $app_env = trim($_POST['APP_ENV'] ?? 'production');
    $app_debug = isset($_POST['APP_DEBUG']) ? 'true' : 'false';

    $db_host = trim($_POST['DB_HOST'] ?? '127.0.0.1');
    $db_port = trim($_POST['DB_PORT'] ?? '3306');
    $db_database = trim($_POST['DB_DATABASE'] ?? 'onlinebank');
    $db_username = trim($_POST['DB_USERNAME'] ?? 'root');
    $db_password = trim($_POST['DB_PASSWORD'] ?? '');

    if(empty($example)){
        $errors[] = '.env.example not found in project root. Please ensure .env.example exists.';
    }

    if(count($errors) === 0){
        // Prepare .env content by replacing common keys; fallback to appending if keys not found
        $envContent = $example;
        $replacements = [
            'APP_ENV' => $app_env,
            'APP_DEBUG' => $app_debug,
            'APP_URL' => $app_url,
            'DB_HOST' => $db_host,
            'DB_PORT' => $db_port,
            'DB_DATABASE' => $db_database,
            'DB_USERNAME' => $db_username,
            'DB_PASSWORD' => $db_password,
        ];
        foreach($replacements as $key => $value){
            if(preg_match('/^' . preg_quote($key) . '\s*=.*/m', $envContent)){
                $envContent = preg_replace('/^' . preg_quote($key) . '\s*=.*/m', $key . '=' . $value, $envContent);
            }else{
                $envContent .= "\n" . $key . '=' . $value;
            }
        }

        // Write .env
        $ok = @write_env($envPath, $envContent);
        if(!$ok){
            $errors[] = 'Unable to write .env at: ' . $envPath . '. Please check permissions.';
        }else{
            $messages[] = '.env file created at ' . $envPath;
            // Test DB connection
            list($dbok, $dbmsg) = test_db($db_host, $db_port, $db_database, $db_username, $db_password);
            if($dbok){
                $messages[] = 'Database connection successful.';
            }else{
                $errors[] = 'Database connection failed: ' . $dbmsg;
            }

            // Attempt to run key:generate
            $projectDir = escapeshellarg($projectRoot);
            $phpCmd = 'php';
            $cmdKey = "cd {$projectDir} && {$phpCmd} artisan key:generate";
            $resKey = run_command($cmdKey);
            if($resKey['success']){
                $messages[] = 'php artisan key:generate ran successfully.';
            }else{
                $messages[] = 'Could not run php artisan key:generate automatically. You can run: "cd ' . $projectRoot . ' && php artisan key:generate".\nOutput: ' . ($resKey['output'] ?: $resKey['error']);
            }

            // License verification via remote verification server (optional)
            $purchase_code = trim($_POST['purchase_code'] ?? '');
            $license_server_url = trim($_POST['license_server_url'] ?? '');
            if($purchase_code){
                if($license_server_url){
                    if(!function_exists('curl_init')){
                        $errors[] = 'cURL is not available on this server; cannot contact license server.';
                    }else{
                        $payload = json_encode(['purchase_code' => $purchase_code]);
                        $ch = curl_init($license_server_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Accept: application/json',
                            'User-Agent: OnlineBank Installer'
                        ]);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        $res = curl_exec($ch);
                        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $err = curl_error($ch);
                        curl_close($ch);
                        if($res === false){
                            $errors[] = 'License server request failed: ' . $err;
                            // save purchase code unverified
                            if(!env_set_key($envPath, 'ENVATO_PURCHASE_CODE', $purchase_code)){
                                $errors[] = 'Could not save ENVATO_PURCHASE_CODE to .env.';
                            }else{
                                $messages[] = 'ENVATO_PURCHASE_CODE saved to .env (unverified).';
                            }
                        }else{
                            $data = json_decode($res, true);
                            if($http === 200 && is_array($data) && !empty($data['valid'])){
                                $messages[] = 'License server verification successful.';
                                if(!env_set_key($envPath, 'ENVATO_PURCHASE_CODE', $purchase_code)){
                                    $errors[] = 'Could not save ENVATO_PURCHASE_CODE to .env.';
                                }
                                // persist license server URL
                                if(!env_set_key($envPath, 'LICENSE_SERVER_URL', $license_server_url)){
                                    $errors[] = 'Could not save LICENSE_SERVER_URL to .env.';
                                }
                            }else{
                                $errMsg = is_array($data) && isset($data['message']) ? $data['message'] : 'Verification failed';
                                $errors[] = 'License server verification failed: ' . $errMsg;
                                if(!env_set_key($envPath, 'ENVATO_PURCHASE_CODE', $purchase_code)){
                                    $errors[] = 'Could not save ENVATO_PURCHASE_CODE to .env.';
                                }else{
                                    $messages[] = 'ENVATO_PURCHASE_CODE saved to .env (verification failed).';
                                }
                            }
                        }
                    }
                }else{
                    // No license server provided: save purchase code for manual verification
                    if(!env_set_key($envPath, 'ENVATO_PURCHASE_CODE', $purchase_code)){
                        $errors[] = 'Could not save ENVATO_PURCHASE_CODE to .env.';
                    }else{
                        $messages[] = 'ENVATO_PURCHASE_CODE saved to .env (unverified). Provide a LICENSE_SERVER_URL later to verify.';
                    }
                }
            }

            // Migrate (ask user confirmation via checkbox)
            if(isset($_POST['run_migrations']) && $_POST['run_migrations'] === 'on'){
                $cmdMigrate = "cd {$projectDir} && {$phpCmd} artisan migrate --force";
                $resMig = run_command($cmdMigrate);
                if($resMig['success']){
                    $messages[] = 'Migrations ran successfully.';
                    if(!empty($resMig['output'])) $messages[] = nl2br(htmlspecialchars($resMig['output']));
                }else{
                    $errors[] = 'Migrations could not be run automatically. Run manually: cd ' . $projectRoot . ' && php artisan migrate --force.\nOutput: ' . ($resMig['output'] ?: $resMig['error']);
                }
            }

            // Import SQL dump if uploaded (required on final submit)
            if(isset($_POST['final_submit']) && $_POST['final_submit'] === '1' && (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK)){
                $errors[] = 'SQL import is required: please upload a .sql file on the Import SQL step.';
            } elseif(isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK){
                $tmp = $_FILES['sql_file']['tmp_name'];
                $sqlContent = file_get_contents($tmp);
                if($sqlContent !== false){
                    $mysqli = @new mysqli($db_host, $db_username, $db_password, $db_database, (int)$db_port);
                    if($mysqli && $mysqli->connect_errno === 0){
                        if($mysqli->multi_query($sqlContent)){
                            do { /* flush */ } while ($mysqli->more_results() && $mysqli->next_result());
                            $messages[] = 'SQL import completed.';
                        }else{
                            $errors[] = 'SQL import failed: ' . $mysqli->error;
                        }
                        $mysqli->close();
                    }else{
                        $errors[] = 'Cannot import SQL: DB connection failed (' . ($mysqli ? $mysqli->connect_error : 'unknown') . ')';
                    }
                }else{
                    $errors[] = 'Uploaded SQL file could not be read.';
                }
            }

            // Extract assets ZIP if uploaded (vendor/ and public/)
            if(isset($_FILES['assets_zip']) && $_FILES['assets_zip']['error'] === UPLOAD_ERR_OK){
                $zipTmp = $_FILES['assets_zip']['tmp_name'];
                $zip = new ZipArchive();
                if($zip->open($zipTmp) === true){
                    $extractPath = $projectRoot;
                    if($zip->extractTo($extractPath)){
                        $messages[] = 'Assets ZIP extracted to project root. Verify vendor/ and public/ were placed correctly.';
                    }else{
                        $errors[] = 'Could not extract assets ZIP to ' . $extractPath;
                    }
                    $zip->close();
                }else{
                    $errors[] = 'Uploaded assets ZIP is not a valid ZIP file.';
                }
            }

            // Create admin user if provided
            $admin_email = trim($_POST['admin_email'] ?? '');
            $admin_name = trim($_POST['admin_name'] ?? 'Admin');
            $admin_password = trim($_POST['admin_password'] ?? '');
            if($admin_email && $admin_password){
                $mysqli = @new mysqli($db_host, $db_username, $db_password, $db_database, (int)$db_port);
                if($mysqli && $mysqli->connect_errno === 0){
                    $emailEsc = $mysqli->real_escape_string($admin_email);
                    $check = $mysqli->query("SELECT id FROM users WHERE email='" . $emailEsc . "' LIMIT 1");
                    if($check && $check->num_rows > 0){
                        $messages[] = 'Admin user already exists with that email.';
                    }else{
                        $pwdHash = password_hash($admin_password, PASSWORD_BCRYPT);
                        $nameEsc = $mysqli->real_escape_string($admin_name);
                        try{ $token = bin2hex(random_bytes(16)); } catch(Exception $e){ $token = substr(md5(uniqid('',true)),0,32); }
                        $now = date('Y-m-d H:i:s');
                        $insertSql = "INSERT INTO users (name,email,password,email_verified_at,remember_token,created_at,updated_at) VALUES ('" . $nameEsc . "','" . $emailEsc . "','" . $pwdHash . "','" . $now . "','" . $token . "','" . $now . "','" . $now . "')";
                        if($mysqli->query($insertSql)){
                            $messages[] = 'Admin user created: ' . htmlspecialchars($admin_email);
                        }else{
                            $errors[] = 'Could not create admin user: ' . $mysqli->error;
                        }
                    }
                    if($check) $check->close();
                    $mysqli->close();
                }else{
                    $errors[] = 'Cannot create admin user: DB connection failed (' . ($mysqli ? $mysqli->connect_error : 'unknown') . ')';
                }
            }

            // If this was the final submission and no errors, redirect to app homepage
            if(isset($_POST['final_submit']) && $_POST['final_submit'] === '1' && count($errors) === 0){
                // Determine redirect URL from provided APP_URL or fallback to '/'
                $redirect = $app_url ?: '/';
                // Ensure it's a valid URL or path
                header('Location: ' . $redirect);
                exit;
            }

            $step = 'done';
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>OnlineBank Installer</title>
    <meta name="theme-color" content="#2563eb">
    <!-- Fonts: use Inter for a modern, clean UI -->
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
    <header>
        <div class="brand-badge">OB</div>
        <div>
            <h1>OnlineBank Installer</h1>
            <p class="muted">This installer will create <code>.env</code>, test DB connection and optionally run migrations, import SQL dumps, extract vendor/assets and create an admin user. Remove the <code>installer/</code> folder after finishing.</p>
        </div>
    </header>

    <?php if(!empty($errors)): ?>
        <div class="alert error">
            <strong>Errors:</strong>
            <ul>
                <?php foreach($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if(!empty($messages)): ?>
        <div class="alert success">
            <strong>Info:</strong>
            <ul>
                <?php foreach($messages as $m): ?>
                    <li><?php echo $m; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if($step === 'form'): ?>
    <form id="installerForm" method="post" class="installer-form" enctype="multipart/form-data">
        <input type="hidden" name="final_submit" id="final_submit" value="0">
        <h2>Application Settings</h2>
        <!-- Step wizard UI will control visibility of sections -->
        <div id="stepIndicator" class="note">Step <span id="currentStep">1</span> of 4</div>
        <div class="step" data-step="1">
            <label>Purchase code (Envato) <input name="purchase_code" id="purchase_code" value="<?php echo htmlspecialchars($_POST['purchase_code'] ?? ''); ?>" placeholder="Enter purchase code"></label>
            <label>License server URL <input name="license_server_url" id="license_server_url" value="<?php echo htmlspecialchars($_POST['license_server_url'] ?? ''); ?>" placeholder="https://your-verification-server.com/api/verify"></label>
            <div class="actions">
                <button type="button" id="verifyButton" class="btn ghost">Verify License</button>
                <div id="verifyResult" class="note"></div>
            </div>
            <div class="note">You can proceed without verification (will save purchase code as unverified) by checking the checkbox at the end of this step.</div>
            <label class="checkbox-row"><input type="checkbox" id="proceed_unverified" name="proceed_unverified"> Proceed without verification (save purchase code unverified)</label>
        </div>

        <div class="step" data-step="2" style="display:none">
            <label>APP_URL
                <input name="APP_URL" value="<?php echo htmlspecialchars($_POST['APP_URL'] ?? 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')); ?>">
            </label>
            <label>APP_ENV
                <select name="APP_ENV">
                    <option value="production" <?php if(($_POST['APP_ENV'] ?? '')==='production') echo 'selected'; ?>>production</option>
                    <option value="local" <?php if(($_POST['APP_ENV'] ?? '')==='local') echo 'selected'; ?>>local</option>
                </select>
            </label>
            <label><input type="checkbox" name="APP_DEBUG" <?php if(isset($_POST['APP_DEBUG'])) echo 'checked'; ?>> Enable APP_DEBUG</label>
            <h2>Database Settings</h2>
            <div class="grid">
                <label>DB_HOST <input name="DB_HOST" value="<?php echo htmlspecialchars($_POST['DB_HOST'] ?? '127.0.0.1'); ?>"></label>
                <label>DB_PORT <input name="DB_PORT" value="<?php echo htmlspecialchars($_POST['DB_PORT'] ?? '3306'); ?>"></label>
                <label>DB_DATABASE <input name="DB_DATABASE" value="<?php echo htmlspecialchars($_POST['DB_DATABASE'] ?? 'onlinebank'); ?>"></label>
                <label>DB_USERNAME <input name="DB_USERNAME" value="<?php echo htmlspecialchars($_POST['DB_USERNAME'] ?? 'root'); ?>"></label>
                <label class="full">DB_PASSWORD <input name="DB_PASSWORD" value="<?php echo htmlspecialchars($_POST['DB_PASSWORD'] ?? ''); ?>" type="password"></label>
            </div>
            <label class="checkbox-row"><input type="checkbox" name="run_migrations"> Run migrations automatically (requires SSH/exec privileges)</label>
        </div>
        <div class="step" data-step="3" style="display:none">
            <h2>Import SQL (required)</h2>
            <label class="note">You must upload a SQL dump (.sql) on this step. The installer will import it into the configured database.</label>
            <label class="full">SQL dump (.sql) to import <input type="file" name="sql_file" id="sql_file" accept=".sql"></label>
            <label class="full">ZIP with <code>vendor/</code> and/or <code>public/</code> assets (optional)
                <input type="file" name="assets_zip" accept=".zip">
            </label>
        </div>

        <div class="step" data-step="4" style="display:none">
            <h2>Create Admin User</h2>
            <label>Admin name <input name="admin_name" id="admin_name" value="<?php echo htmlspecialchars($_POST['admin_name'] ?? 'Admin'); ?>"></label>
            <label>Admin email <input name="admin_email" id="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" type="email"></label>
            <label>Admin password <input type="password" id="admin_password" name="admin_password" value=""></label>

            <label class="checkbox-row"><input type="checkbox" name="confirm" id="confirm_checkbox"> I understand this will create/overwrite <code>.env</code>, may run migrations and import SQL. I will remove <code>installer/</code> after use.</label>

            <div class="actions">
                <button type="button" class="btn" id="prevBtn">Back</button>
                <button type="button" class="btn ghost" id="cancelBtn">Cancel</button>
                <button type="button" class="btn primary" id="finalInstallBtn">Install</button>
            </div>
        </div>
        <div class="actions" style="margin-top:10px">
            <button type="button" class="btn" id="backButton" style="display:none">Back</button>
            <button type="button" class="btn primary" id="nextButton">Next</button>
        </div>
    </form>
    <?php else: ?>
        <div class="next-steps">
            <h3>Next steps</h3>
            <ul>
                <li>Run <code>composer install --no-dev --optimize-autoloader</code> in the project root (via SSH) if vendor wasn't uploaded.</li>
                <li>Build frontend assets: <code>npm install && npm run prod</code> if assets were not provided.</li>
                <li>Ensure permissions for <code>storage</code> and <code>bootstrap/cache</code> are set correctly.</li>
                <li>Remove the <code>installer/</code> directory to prevent misuse: <code>rm -rf installer</code></li>
            </ul>
        </div>
    <?php endif; ?>

    <footer>
        <p class="muted">Installer created for packaging. Review output and execute unresolved commands manually if needed.</p>
    </footer>
</div>
<script>
// Multi-step wizard and license verification
(function(){
    const steps = Array.from(document.querySelectorAll('.step'));
    let current = 0;
    const currentStepEl = document.getElementById('currentStep');
    const nextButton = document.getElementById('nextButton');
    const backButton = document.getElementById('backButton');
    const verifyBtn = document.getElementById('verifyButton');
    const verifyResult = document.getElementById('verifyResult');
    const proceedUnverified = document.getElementById('proceed_unverified');
    const form = document.getElementById('installerForm');
    const finalInstallBtn = document.getElementById('finalInstallBtn');
    const finalSubmitInput = document.getElementById('final_submit');

    function showStep(i){
        steps.forEach(s=> s.style.display='none');
        steps[i].style.display='block';
        current = i;
        currentStepEl.textContent = (i+1);
        backButton.style.display = i>0 ? 'inline-flex' : 'none';
        nextButton.style.display = i<steps.length-1 ? 'inline-flex' : 'none';
    }

    nextButton.addEventListener('click', function(){
        // simple validation: on step 1 ensure either verification succeeded or proceed_unverified checked
        if(current === 0){
            if(!verifyResult.dataset.verified && !proceedUnverified.checked){
                alert('Please verify the license or choose to proceed without verification.');
                return;
            }
        }
        if(current === 2){
            // ensure SQL file present
            const sql = document.getElementById('sql_file');
            if(!sql || !sql.files || sql.files.length === 0){
                alert('Please upload a SQL dump (.sql) on this step.');
                return;
            }
        }
        showStep(Math.min(steps.length-1, current+1));
    });

    backButton.addEventListener('click', function(){ showStep(Math.max(0, current-1)); });

    verifyBtn.addEventListener('click', function(){
        const code = document.getElementById('purchase_code').value.trim();
        const url = document.getElementById('license_server_url').value.trim();
        verifyResult.textContent = 'Verifying...';
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=verify_license&purchase_code='+encodeURIComponent(code)+'&license_server_url='+encodeURIComponent(url)
        }).then(r=>r.json()).then(function(data){
            if(data.success){
                verifyResult.textContent = 'Verified ✓';
                verifyResult.style.color = '#059669';
                verifyResult.dataset.verified = '1';
            } else {
                verifyResult.textContent = data.message || 'Verification failed';
                verifyResult.style.color = '#b91c1c';
                verifyResult.dataset.verified = '';
            }
        }).catch(function(err){
            verifyResult.textContent = 'Network error';
            verifyResult.style.color = '#b91c1c';
            verifyResult.dataset.verified = '';
        });
    });

    // Final install button: set final_submit then submit
    if(finalInstallBtn){
        finalInstallBtn.addEventListener('click', function(){
            if(!document.getElementById('confirm_checkbox') || !document.getElementById('confirm_checkbox').checked){
                alert('Please confirm you understand the installer will create/overwrite .env and may run migrations.');
                return;
            }
            finalSubmitInput.value = '1';
            form.submit();
        });
    }

    // initial
    showStep(0);
})();
</script>
</body>
</html>
