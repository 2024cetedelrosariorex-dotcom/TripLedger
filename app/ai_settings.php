<?php
require_once __DIR__ . '/db.php';

function aiSettings(): array
{
    // Fail closed until migrations are installed.
    try {
        return db()->query('SELECT enabled, explanations_enabled, analysis_enabled, model FROM ai_settings WHERE id = 1')->fetch()
            ?: ['enabled' => 0, 'explanations_enabled' => 0, 'analysis_enabled' => 0, 'model' => ''];
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        return ['enabled' => 0, 'explanations_enabled' => 0, 'analysis_enabled' => 0, 'model' => ''];
    }
}

function aiAllowedModels(): array
{
    $config = tripledgerConfig()['gemini'] ?? [];
    $models = $config['allowed_models'] ?? [];
    if (!empty($config['model'])) $models[] = $config['model'];
    return array_values(array_unique(array_filter($models, static fn($model) =>
        is_string($model) && preg_match('/^gemini-[a-z0-9.-]{1,80}$/', $model))));
}

function aiRequireEnabled(string $feature): array
{
    $settings = aiSettings();
    if (!in_array($feature, ['explanations', 'analysis'], true)
        || !(int) $settings['enabled'] || !(int) $settings[$feature . '_enabled']) {
        throw new RuntimeException('Gemini is disabled for this feature. Local record search remains available.');
    }
    return $settings;
}

function saveAiSettings(int $adminId, array $values): void
{
    $models = aiAllowedModels();
    if (!is_string($values['model'] ?? null) || ($values['model'] !== '' && !in_array($values['model'], $models, true))) {
        throw new InvalidArgumentException('Select a model permitted by the server configuration.');
    }
    $new = ['enabled' => !empty($values['enabled']) ? 1 : 0,
        'explanations_enabled' => !empty($values['explanations_enabled']) ? 1 : 0,
        'analysis_enabled' => !empty($values['analysis_enabled']) ? 1 : 0,
        'model' => $values['model']];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $actor = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'Administrator' AND is_active = 1 FOR UPDATE");
        $actor->execute([$adminId]);
        if (!$actor->fetchColumn()) throw new InvalidArgumentException('Active Administrator required.');
        $old = $pdo->query('SELECT enabled, explanations_enabled, analysis_enabled, model FROM ai_settings WHERE id = 1 FOR UPDATE')->fetch();
        if (!$old) throw new RuntimeException('Apply database migrations first.');
        $stmt = $pdo->prepare('UPDATE ai_settings SET enabled = ?, explanations_enabled = ?, analysis_enabled = ?, model = ? WHERE id = 1');
        $stmt->execute(array_values($new));
        if ($old != $new) {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, old_values, new_values) VALUES (?, 'AI_SETTINGS_CHANGED', ?, ?)");
            $stmt->execute([$adminId, json_encode($old, JSON_THROW_ON_ERROR), json_encode($new, JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
