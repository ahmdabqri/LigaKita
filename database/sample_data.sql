-- LigaKita sample data for testing and demonstration (fictional names).
-- Import AFTER ligakita.sql. Team manager logins: benutfc_mgr ... penerokfc_mgr, password manager123
USE ligakita;

INSERT INTO competitions (competition_id, created_by, name, season, organiser, format, start_date, end_date, description, status) VALUES
(1, 1, 'Pontian District Youth Cup 2026', 2026, 'Pontian District Football Association', 'group_knockout', '2026-12-12', '2027-01-09', 'Sample competition for testing.', 'upcoming'),
(2, 1, 'Kukup Community League 2026', 2026, 'Kukup Community Committee', 'league', '2026-10-03', '2026-12-19', NULL, 'ongoing');

INSERT INTO categories (category_id, competition_id, name, born_on_or_after, min_squad, max_squad, half_minutes, reg_closes) VALUES
(1, 1, 'U12', '2014-01-01', 11, 18, 25, '2026-12-05'),
(2, 1, 'U15', '2011-01-01', 11, 20, 30, '2026-12-05'),
(3, 1, 'U17', '2009-01-01', 11, 22, 35, '2026-12-05'),
(4, 2, 'OPEN', '1950-01-01', 11, 25, 40, '2026-09-26');

INSERT INTO competition_rules (category_id, pts_win, pts_draw, pts_loss, tiebreak_order, qualify_per_group, best_thirds, ko_draw_rule, yellow_limit, red_ban, clear_yellows, max_per_day) VALUES
(2, 3, 1, 0, 'GD,GF,H2H,FP', 2, 2, 'et_pens', 2, 1, 0, 1);

INSERT INTO users (user_id, full_name, username, password_hash, role, phone, club_name, status, must_change_pw) VALUES
(2, 'Manager name 1', 'benutfc_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6700', 'Benut FC', 'active', 0),
(3, 'Manager name 2', 'pontianun_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6701', 'Pontian United', 'active', 0),
(4, 'Manager name 3', 'rambahrov_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6702', 'Rambah Rovers', 'active', 0),
(5, 'Manager name 4', 'kukupfc_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6703', 'Kukup FC', 'active', 0),
(6, 'Manager name 5', 'airbaloif_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6704', 'Air Baloi FC', 'active', 0),
(7, 'Manager name 6', 'serkatuni_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6705', 'Serkat United', 'active', 0),
(8, 'Manager name 7', 'pekannana_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6706', 'Pekan Nanas FC', 'active', 0),
(9, 'Manager name 8', 'sanglangf_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6707', 'Sanglang FC', 'active', 0),
(10, 'Manager name 9', 'jerambatu_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6708', 'Jeram Batu FC', 'active', 0),
(11, 'Manager name 10', 'rimbaterj_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6709', 'Rimba Terjun FC', 'active', 0),
(12, 'Manager name 11', 'ayermasin_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6710', 'Ayer Masin FC', 'active', 0),
(13, 'Manager name 12', 'penerokfc_mgr', '$2y$10$AYuiqxnNVBaP04aV.QARE.qUvbov9g1ecT0Ak4PFiHLabsGGvTeEm', 'team_manager', '012-345 6711', 'Penerok FC', 'active', 0);

INSERT INTO teams (team_id, category_id, manager_id, team_name, short_name, organisation, coach_name, reg_status, submitted_at) VALUES
(1, 2, 2, 'Benut FC', 'BF', 'Kelab Bola Sepak Benut', 'Coach name 1', 'approved', '2026-11-20 10:00:00'),
(2, 2, 3, 'Pontian United', 'PU', 'Kelab Bola Sepak Pontian United', 'Coach name 2', 'approved', '2026-11-20 10:00:00'),
(3, 2, 4, 'Rambah Rovers', 'RR', 'Kelab Bola Sepak Rambah Rovers', 'Coach name 3', 'approved', '2026-11-20 10:00:00'),
(4, 2, 5, 'Kukup FC', 'KF', 'Kelab Bola Sepak Kukup', 'Coach name 4', 'approved', '2026-11-20 10:00:00'),
(5, 2, 6, 'Air Baloi FC', 'ABF', 'Kelab Bola Sepak Air Baloi', 'Coach name 5', 'approved', '2026-11-20 10:00:00'),
(6, 2, 7, 'Serkat United', 'SU', 'Kelab Bola Sepak Serkat United', 'Coach name 6', 'approved', '2026-11-20 10:00:00'),
(7, 2, 8, 'Pekan Nanas FC', 'PNF', 'Kelab Bola Sepak Pekan Nanas', 'Coach name 7', 'approved', '2026-11-20 10:00:00'),
(8, 2, 9, 'Sanglang FC', 'SF', 'Kelab Bola Sepak Sanglang', 'Coach name 8', 'approved', '2026-11-20 10:00:00'),
(9, 2, 10, 'Jeram Batu FC', 'JBF', 'Kelab Bola Sepak Jeram Batu', 'Coach name 9', 'approved', '2026-11-20 10:00:00'),
(10, 2, 11, 'Rimba Terjun FC', 'RTF', 'Kelab Bola Sepak Rimba Terjun', 'Coach name 10', 'approved', '2026-11-20 10:00:00'),
(11, 2, 12, 'Ayer Masin FC', 'AMF', 'Kelab Bola Sepak Ayer Masin', 'Coach name 11', 'approved', '2026-11-20 10:00:00'),
(12, 2, 13, 'Penerok FC', 'PF', 'Kelab Bola Sepak Penerok', 'Coach name 12', 'approved', '2026-11-20 10:00:00');
