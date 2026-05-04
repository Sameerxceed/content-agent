-- Seed: Admin user
-- Password: changeme123 (bcrypt hash)
-- IMPORTANT: Change this password immediately after first login!

INSERT INTO users (email, password_hash, name, plan) VALUES
('sameer@xceed.in', '$2y$12$LJ3m4yd7GKhBqvKdQ9dXHOsP5N0MdJ0m5CknRKhILJ7AGtGSxwIWe', 'Sameer Vitkar', 'agency')
ON DUPLICATE KEY UPDATE name = VALUES(name);
