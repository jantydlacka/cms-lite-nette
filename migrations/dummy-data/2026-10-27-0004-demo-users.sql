-- Demo accounts with PUBLIC passwords (see README); never load demo data on a live site.
-- Hashes are bcrypt; newer PHP versions rehash them at the first sign-in.
INSERT INTO `user` (`email`, `password_hash`, `name`, `role`, `active`, `created_at`) VALUES
('admin@example.com', '$2y$10$y36uubZY99juxDtgD/TYvewsnvflMoqV.Hy2Vpq0DJmH3ojE2eWdi', 'Demo Admin', 'admin', 1, '2026-10-27 00:00:00'),
('editor@example.com', '$2y$10$nPpQqRAe4kn.mTXbynaaAeKJ8KHqtqzJWKQn0kAK4WOggxaOFC/Be', 'Demo Editor', 'editor', 1, '2026-10-27 00:00:00');
