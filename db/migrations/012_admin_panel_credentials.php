<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('admin')) {
        return;
    }
    $admins = $pdo->query("SELECT id_admin, username, password FROM admin")->fetchAll(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare("UPDATE admin SET username = ?, password = ? WHERE id_admin = ?");
    foreach ($admins as $admin) {
        $password = (string) $admin['password'];
        $isHashed = str_starts_with($password, '$2') || str_starts_with($password, '$argon2');
        if ($admin['username'] === 'root') {
            // Never rotate a live administrator password on every bootstrap.
            // Keep the historical username migration, but hash an old plaintext
            // value only once and preserve an existing password hash.
            $safePassword = $isHashed ? $password : password_hash($password, PASSWORD_BCRYPT);
            $statement->execute([$admin['id_admin'], $safePassword, $admin['id_admin']]);
        } elseif (!$isHashed) {
            $statement->execute([$admin['username'], password_hash($password, PASSWORD_BCRYPT), $admin['id_admin']]);
        }
    }
};
