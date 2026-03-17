<?php
require_once "/usr/local/cpanel/php/cpanel.php";
require_once "RedisManager.php";

$cpanel      = new CPANEL();
$redisManager = new RedisManager($cpanel);

// CSRF token — stored in session, validated on mutating actions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['redis_csrf_token'])) {
    $_SESSION['redis_csrf_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['redis_csrf_token'];

try {
    $action = $_POST['action'] ?? 'status';

    // Mutating actions require POST + valid CSRF token
    if (in_array($action, ['start', 'stop', 'reset'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed.");
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrfToken, $submittedToken)) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        switch ($action) {
            case 'start':
                $redisManager->startRedis();
                break;
            case 'stop':
                $redisManager->stopRedis();
                break;
            case 'reset':
                $redisManager->resetRedis();
                break;
        }
        header("Location: index.live.php");
        exit;
    }

    // ---- Read-only status ----
    $status      = $redisManager->getStatus();
    $connInfo    = $status['running'] ? $redisManager->getConnectionInfo() : [];
    $username    = $redisManager->username;

    $isRunning   = $status['running'];
    $isConfigured = $status['configured'];

    $stylesheetsAndMetaTags = '<link rel="stylesheet" href="redis_style.css" charset="utf-8"/>';
    $cpanelHeader = str_replace('</head>', $stylesheetsAndMetaTags . '</head>', $cpanel->header("Valkey / Redis Manager"));
    echo $cpanelHeader;
?>
    <div class="body-content">
        <hr>
        <br>
        <p>
            <strong><a href="https://valkey.io/" target="_blank" rel="noopener">Valkey</a></strong>
            (Redis-compatible, BSD-licensed) is a high-performance in-memory data store used as a
            cache, session store, message broker, and more. Each cPanel account gets its own
            isolated instance — your credentials are private to your account.
        </p>
        <br>

        <div class="panel panel-default">
            <div class="panel-body">
                <div class="header-section">
                    <img src="./redis_icon.webp" alt="Valkey / Redis" width="50" />
                    <h4>Instance Status</h4>
                </div>

                <div class="status-section">
                    <?php if ($isRunning) : ?>
                        <p>
                            <strong>Status:</strong>
                            <span class="badge badge-success">&#9679; Running</span>
                            &nbsp;<small>(<?= htmlspecialchars($status['binary'], ENT_QUOTES, 'UTF-8') ?>)</small>
                        </p>
                        <p>
                            <strong>Host:</strong> 127.0.0.1
                        </p>
                        <p>
                            <strong>Port:</strong>
                            <code id="redis-port"><?= htmlspecialchars($status['port'], ENT_QUOTES, 'UTF-8') ?></code>
                            <button class="btn btn-xs btn-copy" onclick="copyText('redis-port')" title="Copy port">&#128203;</button>
                        </p>
                        <p>
                            <strong>Password:</strong>
                            <code id="redis-pass"><?= htmlspecialchars($status['password'], ENT_QUOTES, 'UTF-8') ?></code>
                            <button class="btn btn-xs btn-copy" onclick="copyText('redis-pass')" title="Copy password">&#128203;</button>
                        </p>
                        <p>
                            <strong>Unix Socket:</strong>
                            <code id="redis-sock"><?= htmlspecialchars($status['socket'], ENT_QUOTES, 'UTF-8') ?></code>
                            <button class="btn btn-xs btn-copy" onclick="copyText('redis-sock')" title="Copy socket path">&#128203;</button>
                        </p>
                        <p><strong>Max Memory:</strong> <?= htmlspecialchars($status['maxmemory'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p><strong>Databases:</strong> <?= htmlspecialchars($status['databases'], ENT_QUOTES, 'UTF-8') ?></p>

                    <?php elseif (!$isConfigured) : ?>
                        <p>
                            <strong>Status:</strong>
                            <span class="badge badge-warning">&#9679; Not Started</span>
                        </p>
                        <p>Click <strong>Start Valkey</strong> below to create and launch your personal instance. First-time startup may take a few seconds.</p>

                    <?php else : ?>
                        <p>
                            <strong>Status:</strong>
                            <span class="badge badge-danger">&#9679; Stopped</span>
                        </p>
                        <p>Your instance is configured but not running. Click <strong>Start Valkey</strong> to restart it.</p>
                    <?php endif; ?>
                </div>

                <hr>

                <!-- Action buttons (POST + CSRF) -->
                <div class="form-inline">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="<?= $isRunning ? 'stop' : 'start' ?>">
                        <button class="btn <?= $isRunning ? 'btn-danger' : 'btn-success' ?>" type="submit">
                            <?= $isRunning ? '&#9646;&#9646; Stop Valkey' : '&#9654; Start Valkey' ?>
                        </button>
                    </form>

                    <?php if ($isConfigured) : ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('This will permanently delete your Redis instance, config, and data. Are you sure?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="reset">
                        <button class="btn btn-warning" type="submit" title="Remove instance and all data">&#128465; Reset &amp; Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isRunning && !empty($connInfo)) : ?>
        <!-- Connection snippets -->
        <div class="panel panel-default">
            <div class="panel-body">
                <div class="header-section">
                    <h4>&#128279; Connection Examples</h4>
                </div>
                <p style="margin-top:12px">Use these snippets to connect your application to your Valkey instance.</p>

                <!-- Tab nav -->
                <div class="tab-bar">
                    <button class="tab-btn active" onclick="showTab(event,'tab-php')">PHP</button>
                    <button class="tab-btn" onclick="showTab(event,'tab-socket')">PHP (Unix Socket)</button>
                    <button class="tab-btn" onclick="showTab(event,'tab-wp')">WordPress</button>
                    <button class="tab-btn" onclick="showTab(event,'tab-laravel')">Laravel</button>
                </div>

                <div id="tab-php" class="tab-pane active">
                    <div class="code-block-wrapper">
                        <pre id="snip-php" class="code-block"><?= htmlspecialchars($connInfo['php'], ENT_QUOTES, 'UTF-8') ?></pre>
                        <button class="btn btn-xs btn-copy float-right" onclick="copyText('snip-php')">&#128203; Copy</button>
                    </div>
                </div>
                <div id="tab-socket" class="tab-pane" style="display:none">
                    <div class="code-block-wrapper">
                        <pre id="snip-socket" class="code-block"><?= htmlspecialchars($connInfo['php_socket'], ENT_QUOTES, 'UTF-8') ?></pre>
                        <button class="btn btn-xs btn-copy float-right" onclick="copyText('snip-socket')">&#128203; Copy</button>
                    </div>
                </div>
                <div id="tab-wp" class="tab-pane" style="display:none">
                    <p style="font-size:12px;color:#555">Requires the <a href="https://wordpress.org/plugins/redis-cache/" target="_blank" rel="noopener">Redis Object Cache</a> plugin.</p>
                    <div class="code-block-wrapper">
                        <pre id="snip-wp" class="code-block"><?= htmlspecialchars($connInfo['wordpress'], ENT_QUOTES, 'UTF-8') ?></pre>
                        <button class="btn btn-xs btn-copy float-right" onclick="copyText('snip-wp')">&#128203; Copy</button>
                    </div>
                </div>
                <div id="tab-laravel" class="tab-pane" style="display:none">
                    <p style="font-size:12px;color:#555">Add these to your Laravel <code>.env</code> file.</p>
                    <div class="code-block-wrapper">
                        <pre id="snip-laravel" class="code-block"><?= htmlspecialchars($connInfo['laravel'], ENT_QUOTES, 'UTF-8') ?></pre>
                        <button class="btn btn-xs btn-copy float-right" onclick="copyText('snip-laravel')">&#128203; Copy</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.body-content -->

<script>
function copyText(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;
    var text = el.innerText || el.textContent;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            flashCopied(elementId);
        });
    } else {
        // Fallback for older browsers
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        flashCopied(elementId);
    }
}

function flashCopied(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;
    var orig = el.style.outline;
    el.style.outline = '2px solid #28a745';
    setTimeout(function() { el.style.outline = orig; }, 1200);
}

function showTab(evt, tabId) {
    var panes = document.querySelectorAll('.tab-pane');
    panes.forEach(function(p) { p.style.display = 'none'; });
    var btns = document.querySelectorAll('.tab-btn');
    btns.forEach(function(b) { b.classList.remove('active'); });
    document.getElementById(tabId).style.display = 'block';
    evt.currentTarget.classList.add('active');
}
</script>

<?php
    print $cpanel->footer();
    $cpanel->end();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    if (isset($cpanel)) {
        print $cpanel->footer();
        $cpanel->end();
    }
}
?>
