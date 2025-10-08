<?php
declare(strict_types=1);

require __DIR__ . '/../src/SemanticEngine.php';

$inputText = isset($_POST['input_text']) ? trim((string) $_POST['input_text']) : '';
$validExports = ['triples', 'synonyms'];
$selectedExports = isset($_POST['exports']) ? (array) $_POST['exports'] : $validExports;
$selectedExports = array_values(array_intersect($validExports, array_map('strval', $selectedExports)));
if ($selectedExports === []) {
    $selectedExports = $validExports;
}

$errors = [];
$results = [
    'triples' => [],
    'synonyms' => [],
];
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    if ($inputText === '') {
        $errors[] = 'Please provide some text to analyse.';
    }

    if ($errors === []) {
        $engine = new SemanticEngine();
        $engine->extractRelations($inputText);

        if (in_array('triples', $selectedExports, true)) {
            $results['triples'] = $engine->iterTriples();
        }

        if (in_array('synonyms', $selectedExports, true)) {
            $results['synonyms'] = $engine->iterSynonyms();
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderTriplesTable(array $triples): string
{
    if ($triples === []) {
        return '<p class="empty">No triples extracted.</p>';
    }

    $rows = [];
    foreach ($triples as $triple) {
        $subject = escape((string) ($triple[0] ?? ''));
        $relation = escape((string) ($triple[1] ?? ''));
        $object = escape((string) ($triple[2] ?? ''));
        $rows[] = "            <tr><td>{$subject}</td><td>{$relation}</td><td>{$object}</td></tr>";
    }

    $table = [
        '        <table class="results-table">',
        '            <thead>',
        '                <tr><th>Subject</th><th>Relation</th><th>Object</th></tr>',
        '            </thead>',
        '            <tbody>',
        implode(PHP_EOL, $rows),
        '            </tbody>',
        '        </table>',
    ];

    return implode(PHP_EOL, $table);
}

function renderSynonymsList(array $synonyms): string
{
    if ($synonyms === []) {
        return '<p class="empty">No synonyms stored.</p>';
    }

    $items = [];
    foreach ($synonyms as $pair) {
        $entity = escape((string) ($pair[0] ?? ''));
        $values = array_map(
            static fn($value): string => escape((string) $value),
            array_values($pair[1] ?? [])
        );
        $items[] = sprintf('<li><span class="entity">%s</span> &rarr; %s</li>', $entity, implode(', ', $values));
    }

    return '<ul class="synonyms-list">' . implode(PHP_EOL, $items) . '</ul>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Semantic Engine Playground</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #f5f5f5;
            color: #1c1c1c;
        }
        body {
            margin: 0;
        }
        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 2rem 1rem 4rem;
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 0.75rem;
        }
        p.subtitle {
            margin-top: 0;
            color: #555;
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        form {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 12px rgba(0, 0, 0, 0.08);
        }
        textarea {
            width: 100%;
            min-height: 180px;
            font: inherit;
            line-height: 1.5;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #ccc;
            resize: vertical;
        }
        fieldset {
            border: none;
            padding: 0;
            margin: 1rem 0;
            display: flex;
            gap: 1rem;
        }
        .checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        button {
            background-color: #2b6cb0;
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 999px;
            cursor: pointer;
        }
        button:hover {
            background-color: #2c5282;
        }
        .messages {
            margin: 1.5rem 0 0;
            list-style: none;
            padding: 0;
        }
        .messages li {
            background: #fed7d7;
            color: #742a2a;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .results {
            margin-top: 2rem;
        }
        .results section {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }
        .results h2 {
            margin-top: 0;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        .results-table th,
        .results-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 0.5rem 0.75rem;
            text-align: left;
        }
        .results-table th {
            background: #f1f5f9;
            font-weight: 600;
        }
        .synonyms-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .synonyms-list li {
            padding: 0.4rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .synonyms-list li:last-child {
            border-bottom: none;
        }
        .synonyms-list .entity {
            font-weight: 600;
        }
        .empty {
            color: #666;
            font-style: italic;
        }
        .account-access {
            margin-top: 3rem;
            background: #ffffff;
            border-radius: 12px;
            padding: 1.75rem;
            box-shadow: 0 1px 12px rgba(0, 0, 0, 0.08);
        }
        .account-access h2 {
            margin-top: 0;
            margin-bottom: 0.5rem;
        }
        .account-access p.description {
            margin-top: 0;
            margin-bottom: 1.5rem;
            color: #4a5568;
        }
        .account-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .account-tab {
            border: 1px solid #cbd5f5;
            background: #ebf4ff;
            color: #2c5282;
            border-radius: 999px;
            padding: 0.5rem 1.25rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        .account-tab[aria-selected="true"],
        .account-tab.active {
            background: #2b6cb0;
            border-color: #2b6cb0;
            color: #fff;
        }
        .account-panel {
            display: none;
        }
        .account-panel.active {
            display: block;
        }
        .auth-forms {
            display: grid;
            gap: 1.25rem;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        .auth-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .auth-card h3 {
            margin: 0;
            font-size: 1.1rem;
        }
        .auth-card label {
            font-weight: 600;
            display: block;
            font-size: 0.95rem;
        }
        .auth-card input[type="email"],
        .auth-card input[type="password"],
        .auth-card input[type="text"] {
            width: 100%;
            padding: 0.65rem 0.75rem;
            border-radius: 8px;
            border: 1px solid #cbd5f5;
            font: inherit;
        }
        .auth-card input[type="email"]:focus,
        .auth-card input[type="password"]:focus,
        .auth-card input[type="text"]:focus {
            outline: 2px solid #63b3ed;
            border-color: #63b3ed;
        }
        .auth-card .helper {
            margin: -0.25rem 0 0;
            font-size: 0.85rem;
            color: #4a5568;
        }
        .auth-card button {
            width: 100%;
            margin-top: 0.5rem;
        }
        .auth-feedback {
            margin-top: 1.5rem;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            display: none;
        }
        .auth-feedback.active {
            display: block;
        }
        .auth-feedback.success {
            background: #c6f6d5;
            color: #22543d;
        }
        .auth-feedback.error {
            background: #fed7d7;
            color: #742a2a;
        }
        .auth-feedback pre {
            margin: 0.75rem 0 0;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.9rem;
        }
        @media (max-width: 640px) {
            fieldset {
                flex-direction: column;
                align-items: flex-start;
            }
            button {
                width: 100%;
            }
            .account-access {
                padding: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <header>
            <h1>Semantic Engine Playground</h1>
            <p class="subtitle">Paste a biography or research summary to extract triples and synonyms.</p>
        </header>
        <form method="post" novalidate>
            <label for="input_text">Input text</label>
            <textarea id="input_text" name="input_text" placeholder="Alice Smith is a Senior Data Scientist. Alice Smith aka Ally Smith." required><?php echo escape($inputText); ?></textarea>
            <fieldset>
                <legend class="sr-only">Data to display</legend>
                <?php foreach ($validExports as $export): ?>
                    <?php $checked = in_array($export, $selectedExports, true) ? 'checked' : ''; ?>
                    <label class="checkbox">
                        <input type="checkbox" name="exports[]" value="<?php echo escape($export); ?>" <?php echo $checked; ?>>
                        <?php echo ucfirst($export); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button type="submit">Run extraction</button>
        </form>

        <?php if ($errors !== []): ?>
            <ul class="messages">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo escape($error); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($submitted && $errors === []): ?>
            <div class="results" aria-live="polite">
                <?php if (in_array('triples', $selectedExports, true)): ?>
                    <section>
                        <h2>Triples</h2>
                        <?php echo renderTriplesTable($results['triples']); ?>
                    </section>
                <?php endif; ?>
                <?php if (in_array('synonyms', $selectedExports, true)): ?>
                    <section>
                        <h2>Synonyms</h2>
                        <?php echo renderSynonymsList($results['synonyms']); ?>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="account-access" id="user-management">
            <h2>User management playground</h2>
            <p class="description">Experiment with the new registration and authentication APIs for both customers and administrators.</p>
            <div class="account-tabs" role="tablist">
                <button type="button" class="account-tab active" id="customer-tab" data-role="customer" role="tab" aria-selected="true">Customer</button>
                <button type="button" class="account-tab" id="admin-tab" data-role="admin" role="tab" aria-selected="false">Administrator</button>
            </div>
            <div class="account-panel active" data-role-panel="customer" role="tabpanel" aria-labelledby="customer-tab">
                <div class="auth-forms">
                    <form class="auth-card" data-auth-form="register" data-role="customer" novalidate>
                        <h3>Register a customer</h3>
                        <p class="helper">Creates a new account with the <code>customer</code> role.</p>
                        <label for="customer-name">Full name</label>
                        <input id="customer-name" name="name" type="text" autocomplete="name" placeholder="Alex Johnson">
                        <label for="customer-email">Email address</label>
                        <input id="customer-email" name="email" type="email" autocomplete="email" required placeholder="alex@example.com">
                        <label for="customer-password">Password</label>
                        <input id="customer-password" name="password" type="password" autocomplete="new-password" minlength="6" required placeholder="At least 6 characters">
                        <button type="submit">Register customer</button>
                    </form>
                    <form class="auth-card" data-auth-form="login" data-role="customer" novalidate>
                        <h3>Customer login</h3>
                        <p class="helper">Authenticate with an existing customer account.</p>
                        <label for="customer-login-email">Email address</label>
                        <input id="customer-login-email" name="email" type="email" autocomplete="email" required placeholder="alex@example.com">
                        <label for="customer-login-password">Password</label>
                        <input id="customer-login-password" name="password" type="password" autocomplete="current-password" required placeholder="Your password">
                        <button type="submit">Login as customer</button>
                    </form>
                </div>
                <div class="auth-feedback" data-auth-feedback="customer" role="status" aria-live="polite"></div>
            </div>
            <div class="account-panel" data-role-panel="admin" role="tabpanel" aria-hidden="true" aria-labelledby="admin-tab">
                <div class="auth-forms">
                    <form class="auth-card" data-auth-form="register" data-role="admin" novalidate>
                        <h3>Register an administrator</h3>
                        <p class="helper">Provision an account with both <code>admin</code> and <code>customer</code> roles.</p>
                        <label for="admin-name">Full name</label>
                        <input id="admin-name" name="name" type="text" autocomplete="name" placeholder="Jamie Rivera">
                        <label for="admin-department">Department</label>
                        <input id="admin-department" name="department" type="text" autocomplete="organization" placeholder="Operations">
                        <label for="admin-email">Email address</label>
                        <input id="admin-email" name="email" type="email" autocomplete="email" required placeholder="jamie@example.com">
                        <label for="admin-password">Password</label>
                        <input id="admin-password" name="password" type="password" autocomplete="new-password" minlength="6" required placeholder="At least 6 characters">
                        <button type="submit">Register administrator</button>
                    </form>
                    <form class="auth-card" data-auth-form="login" data-role="admin" novalidate>
                        <h3>Administrator login</h3>
                        <p class="helper">Authenticate with an administrator account to verify role assignment.</p>
                        <label for="admin-login-email">Email address</label>
                        <input id="admin-login-email" name="email" type="email" autocomplete="email" required placeholder="jamie@example.com">
                        <label for="admin-login-password">Password</label>
                        <input id="admin-login-password" name="password" type="password" autocomplete="current-password" required placeholder="Your password">
                        <button type="submit">Login as administrator</button>
                    </form>
                </div>
                <div class="auth-feedback" data-auth-feedback="admin" role="status" aria-live="polite"></div>
            </div>
        </section>
    </div>
    <script>
    (function () {
        const tabs = Array.from(document.querySelectorAll('.account-tab'));
        const panels = Array.from(document.querySelectorAll('.account-panel'));
        const feedbackAreas = {
            customer: document.querySelector('[data-auth-feedback="customer"]'),
            admin: document.querySelector('[data-auth-feedback="admin"]'),
        };

        function activateRole(role) {
            tabs.forEach((tab) => {
                const isActive = tab.dataset.role === role;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            panels.forEach((panel) => {
                const isActive = panel.dataset.rolePanel === role;
                panel.classList.toggle('active', isActive);
                panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });
        }

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const role = tab.dataset.role || 'customer';
                activateRole(role);
            });
        });

        activateRole('customer');

        const endpoints = {
            register: '/api/users/register',
            login: '/api/users/login',
        };

        const successMessages = {
            register: {
                customer: 'Customer registered successfully.',
                admin: 'Administrator registered successfully.',
            },
            login: {
                customer: 'Customer authenticated successfully.',
                admin: 'Administrator authenticated successfully.',
            },
        };

        function escapeHtml(value) {
            return value
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showFeedback(role, status, message, details) {
            const area = feedbackAreas[role];
            if (!area) {
                return;
            }

            area.classList.remove('success', 'error', 'active');
            if (status) {
                area.classList.add(status);
            }
            area.classList.add('active');

            let content = '<strong>' + escapeHtml(message) + '</strong>';
            if (details) {
                const formatted = typeof details === 'string' ? details : JSON.stringify(details, null, 2);
                content += '<pre>' + escapeHtml(formatted) + '</pre>';
            }
            area.innerHTML = content;
        }

        function collectProfile(formData) {
            const profile = {};
            const name = (formData.get('name') || '').toString().trim();
            const department = (formData.get('department') || '').toString().trim();

            if (name !== '') {
                profile.name = name;
            }

            if (department !== '') {
                profile.department = department;
            }

            return Object.keys(profile).length > 0 ? profile : null;
        }

        async function handleAuthSubmit(event) {
            event.preventDefault();
            const form = event.currentTarget;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const role = form.dataset.role || 'customer';
            const intent = form.dataset.authForm || 'login';
            const endpoint = endpoints[intent];
            if (!endpoint) {
                return;
            }

            const formData = new FormData(form);
            const email = (formData.get('email') || '').toString().trim();
            const password = (formData.get('password') || '').toString();

            if (email === '' || password === '') {
                showFeedback(role, 'error', 'Email and password are required.');
                return;
            }

            const payload = {
                email,
                password,
            };

            if (intent === 'register') {
                const profile = collectProfile(formData);
                if (profile) {
                    payload.profile = profile;
                }
                payload.roles = role === 'admin' ? ['admin', 'customer'] : ['customer'];
            }

            const submitButton = form.querySelector('button[type="submit"]');
            const originalText = submitButton ? submitButton.textContent : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Please wait...';
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload),
                });

                const raw = await response.text();
                let data = null;
                if (raw !== '') {
                    try {
                        data = JSON.parse(raw);
                    } catch (error) {
                        // Non-JSON response; keep raw string for feedback.
                        data = raw;
                    }
                }

                if (!response.ok) {
                    const message = typeof data === 'object' && data !== null && 'error' in data
                        ? String(data.error)
                        : 'Unable to complete the request.';
                    showFeedback(role, 'error', message, data);
                    return;
                }

                const successMessage = successMessages[intent]?.[role] || 'Request completed successfully.';
                const user = typeof data === 'object' && data !== null && 'user' in data
                    ? data.user
                    : null;
                showFeedback(role, 'success', successMessage, user);

                if (intent === 'register') {
                    form.reset();
                }
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Unexpected error occurred.';
                showFeedback(role, 'error', message);
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                }
            }
        }

        document.querySelectorAll('[data-auth-form]').forEach((form) => {
            form.addEventListener('submit', handleAuthSubmit);
        });
    })();
    </script>
</body>
</html>
