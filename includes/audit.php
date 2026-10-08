<?php

function bmjs_audit_redact(mixed $value): mixed
{
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = (string) $key;
            if (stripos($normalizedKey, 'password') !== false) {
                $result[$key] = '[redacted]';
                continue;
            }

            $result[$key] = bmjs_audit_redact($item);
        }
        return $result;
    }

    if (is_string($value)) {
        return stripos($value, 'password') !== false || preg_match('/\$2[ayb]\$\d+\$/', $value) ? '[redacted]' : $value;
    }

    return $value;
}

function log_action(string $action, ?string $table = null, ?int $record_id = null, mixed $old = null, mixed $new = null): void
{
    try {
        $userId = null;
        if (!empty($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
        }

        $statement = db()->prepare(
            'INSERT INTO audit_log (user_id, action, table_name, record_id, old_value, new_value, ip) VALUES (:user_id, :action, :table_name, :record_id, :old_value, :new_value, :ip)'
        );

        $statement->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':table_name' => $table,
            ':record_id' => $record_id,
            ':old_value' => json_encode(bmjs_audit_redact($old), JSON_THROW_ON_ERROR),
            ':new_value' => json_encode(bmjs_audit_redact($new), JSON_THROW_ON_ERROR),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $exception) {
        error_log('audit log failed: ' . $exception->getMessage());
    }
}
