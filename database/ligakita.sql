-- LigaKita: Web-Based Football Tournament Management System
-- Database schema (15 tables). Matches the data dictionary in Chapter 4, section 4.3.3.
-- Import this file in phpMyAdmin (Import tab) or run: mysql -u root < database/ligakita.sql

CREATE DATABASE IF NOT EXISTS ligakita CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ligakita;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `suspensions`;
DROP TABLE IF EXISTS `match_events`;
DROP TABLE IF EXISTS `match_results`;
DROP TABLE IF EXISTS `matches`;
DROP TABLE IF EXISTS `team_players`;
DROP TABLE IF EXISTS `players`;
DROP TABLE IF EXISTS `group_teams`;
DROP TABLE IF EXISTS `teams`;
DROP TABLE IF EXISTS `match_slots`;
DROP TABLE IF EXISTS `venues`;
DROP TABLE IF EXISTS `groups`;
DROP TABLE IF EXISTS `competition_rules`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `competitions`;
DROP TABLE IF EXISTS `users`;

-- D1 Users: Login accounts of the tournament administrator and the team managers.
CREATE TABLE `users` (
  `user_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique user identification number',
  `full_name` VARCHAR(100) NOT NULL COMMENT 'Full name of the user',
  `username` VARCHAR(50) NOT NULL COMMENT 'Name used to log in',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'Hashed password',
  `role` ENUM('admin', 'team_manager') NOT NULL COMMENT 'Role of the user',
  `phone` VARCHAR(15) NOT NULL COMMENT 'Mobile phone number',
  `email` VARCHAR(100) NULL COMMENT 'E-mail address',
  `club_name` VARCHAR(150) NULL COMMENT 'Club that a team manager represents',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active' COMMENT 'Whether the account can log in',
  `must_change_pw` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Forces a password change at first login',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time the account was created',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D2 Competitions: A tournament organised by the committee, such as a district youth cup.
CREATE TABLE `competitions` (
  `competition_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique competition identification number',
  `created_by` INT NOT NULL COMMENT 'Administrator who created the competition',
  `name` VARCHAR(150) NOT NULL COMMENT 'Name of the competition',
  `season` YEAR NOT NULL COMMENT 'Season of the competition',
  `organiser` VARCHAR(150) NULL COMMENT 'Organising body',
  `format` ENUM('league', 'knockout', 'group_knockout') NOT NULL COMMENT 'Competition format',
  `start_date` DATE NOT NULL COMMENT 'First day of the competition',
  `end_date` DATE NOT NULL COMMENT 'Last day of the competition',
  `description` TEXT NULL COMMENT 'Notes about the competition',
  `status` ENUM('upcoming', 'ongoing', 'completed') NOT NULL DEFAULT 'upcoming' COMMENT 'Stage of the competition',
  PRIMARY KEY (`competition_id`),
  CONSTRAINT `fk_competitions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D2 Competitions: An age category within a competition, such as U15.
CREATE TABLE `categories` (
  `category_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique category identification number',
  `competition_id` INT NOT NULL COMMENT 'Competition that the category belongs to',
  `name` VARCHAR(20) NOT NULL COMMENT 'Name of the category',
  `born_on_or_after` DATE NOT NULL COMMENT 'Earliest date of birth allowed',
  `min_squad` TINYINT(2) NOT NULL COMMENT 'Minimum number of players per team',
  `max_squad` TINYINT(2) NOT NULL COMMENT 'Maximum number of players per team',
  `half_minutes` TINYINT(2) NOT NULL COMMENT 'Length of each half in minutes',
  `reg_closes` DATE NOT NULL COMMENT 'Last day for team registration',
  PRIMARY KEY (`category_id`),
  CONSTRAINT `fk_categories_competition_id` FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`competition_id`) ON DELETE RESTRICT,
  UNIQUE KEY `uq_categories_name` (`competition_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D2 Competitions: The points, tie-break, qualification and discipline rules of a category.
CREATE TABLE `competition_rules` (
  `rule_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique rule set identification number',
  `category_id` INT NOT NULL COMMENT 'Category that the rules apply to',
  `pts_win` TINYINT(1) NOT NULL DEFAULT 3 COMMENT 'Points for a win',
  `pts_draw` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Points for a draw',
  `pts_loss` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Points for a loss',
  `tiebreak_order` VARCHAR(50) NOT NULL COMMENT 'Tie-break criteria in order of use',
  `qualify_per_group` TINYINT(1) NOT NULL COMMENT 'Teams that qualify from each group',
  `best_thirds` TINYINT(1) NOT NULL COMMENT 'Best third-placed teams that also qualify',
  `ko_draw_rule` ENUM('et_pens', 'pens') NOT NULL COMMENT 'How a drawn knockout match is decided',
  `yellow_limit` TINYINT(1) NOT NULL COMMENT 'Yellow cards that lead to a ban',
  `red_ban` TINYINT(1) NOT NULL COMMENT 'Matches banned for a red card',
  `clear_yellows` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Clears yellow cards after the group stage',
  `max_per_day` TINYINT(1) NOT NULL COMMENT 'Maximum matches per team per day',
  PRIMARY KEY (`rule_id`),
  CONSTRAINT `fk_competition_rules_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_competition_rules_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D2 Competitions: A group within a category for the group stage.
CREATE TABLE `groups` (
  `group_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique group identification number',
  `category_id` INT NOT NULL COMMENT 'Category that the group belongs to',
  `group_name` VARCHAR(10) NOT NULL COMMENT 'Name of the group',
  PRIMARY KEY (`group_id`),
  CONSTRAINT `fk_groups_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE RESTRICT,
  UNIQUE KEY `uq_groups_name` (`category_id`, `group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D3 Venues and Slots: A pitch that can be used for matches.
CREATE TABLE `venues` (
  `venue_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique venue identification number',
  `name` VARCHAR(100) NOT NULL COMMENT 'Name of the venue',
  `town` VARCHAR(50) NULL COMMENT 'Town or address of the venue',
  PRIMARY KEY (`venue_id`),
  UNIQUE KEY `uq_venues_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D3 Venues and Slots: A kick-off time at a venue on a match day.
CREATE TABLE `match_slots` (
  `slot_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique slot identification number',
  `competition_id` INT NOT NULL COMMENT 'Competition that can use the slot',
  `venue_id` INT NOT NULL COMMENT 'Venue of the slot',
  `slot_date` DATE NOT NULL COMMENT 'Match day',
  `kickoff` TIME NOT NULL COMMENT 'Kick-off time',
  `length_min` SMALLINT(3) NOT NULL COMMENT 'Slot length including the break',
  PRIMARY KEY (`slot_id`),
  CONSTRAINT `fk_match_slots_competition_id` FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`competition_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_match_slots_venue_id` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`venue_id`) ON DELETE RESTRICT,
  UNIQUE KEY `uq_match_slots_time` (`venue_id`, `slot_date`, `kickoff`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D4 Teams and Players: A team registered in a category by its team manager.
CREATE TABLE `teams` (
  `team_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique team identification number',
  `category_id` INT NOT NULL COMMENT 'Category entered',
  `manager_id` INT NOT NULL COMMENT 'Team manager responsible for the team',
  `team_name` VARCHAR(100) NOT NULL COMMENT 'Name of the team',
  `short_name` VARCHAR(10) NULL COMMENT 'Short name of the team',
  `organisation` VARCHAR(150) NOT NULL COMMENT 'Club, school or organisation',
  `logo_path` VARCHAR(255) NULL COMMENT 'File path of the team logo',
  `coach_name` VARCHAR(100) NOT NULL COMMENT 'Name of the head coach',
  `coach_phone` VARCHAR(15) NULL COMMENT 'Phone number of the head coach',
  `home_kit` VARCHAR(50) NULL COMMENT 'Home kit colours',
  `away_kit` VARCHAR(50) NULL COMMENT 'Away kit colours',
  `reg_status` ENUM('draft', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'draft' COMMENT 'Registration status of the team',
  `reject_reason` VARCHAR(255) NULL COMMENT 'Reason given when rejected',
  `submitted_at` DATETIME NULL COMMENT 'Date and time submitted for approval',
  PRIMARY KEY (`team_id`),
  CONSTRAINT `fk_teams_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_teams_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  UNIQUE KEY `uq_teams_name` (`category_id`, `team_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D2 Competitions: The teams placed in each group (result of the draw).
CREATE TABLE `group_teams` (
  `group_team_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique identification number',
  `group_id` INT NOT NULL COMMENT 'Group',
  `team_id` INT NOT NULL COMMENT 'Team placed in the group',
  PRIMARY KEY (`group_team_id`),
  CONSTRAINT `fk_group_teams_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`group_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_teams_team_id` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE RESTRICT,
  UNIQUE KEY `uq_group_teams_team_id` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D4 Teams and Players: A player, recorded once and registered to a team through team_players.
CREATE TABLE `players` (
  `player_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique player identification number',
  `full_name` VARCHAR(100) NOT NULL COMMENT 'Name as in MyKad or birth certificate',
  `id_number` VARCHAR(14) NOT NULL COMMENT 'MyKad or birth certificate number',
  `date_of_birth` DATE NOT NULL COMMENT 'Date of birth',
  `photo_path` VARCHAR(255) NULL COMMENT 'File path of the player photo',
  PRIMARY KEY (`player_id`),
  UNIQUE KEY `uq_players_id_number` (`id_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D4 Teams and Players: Registration of a player in a team for a competition.
CREATE TABLE `team_players` (
  `team_player_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique registration identification number',
  `team_id` INT NOT NULL COMMENT 'Team',
  `player_id` INT NOT NULL COMMENT 'Player',
  `jersey_no` TINYINT(2) NOT NULL COMMENT 'Jersey number',
  `position` ENUM('GK', 'DF', 'MF', 'FW') NULL COMMENT 'Playing position',
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'Approval status of the registration',
  PRIMARY KEY (`team_player_id`),
  CONSTRAINT `fk_team_players_team_id` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_players_player_id` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE RESTRICT,
  UNIQUE KEY `uq_team_players_jersey` (`team_id`, `jersey_no`),
  UNIQUE KEY `uq_team_players_player` (`team_id`, `player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D5 Matches: A group or knockout match, including its place in the bracket.
CREATE TABLE `matches` (
  `match_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique match identification number',
  `category_id` INT NOT NULL COMMENT 'Category of the match',
  `group_id` INT NULL COMMENT 'Group, for group stage matches',
  `slot_id` INT NULL COMMENT 'Venue and kick-off slot',
  `home_team_id` INT NULL COMMENT 'Home team',
  `away_team_id` INT NULL COMMENT 'Away team',
  `next_match_id` INT NULL COMMENT 'Knockout match that the winner goes to',
  `next_side` ENUM('home', 'away') NULL COMMENT 'Side the winner takes in the next match',
  `stage` ENUM('group', 'r16', 'qf', 'sf', 'final') NOT NULL COMMENT 'Stage of the competition',
  `match_no` SMALLINT(3) NOT NULL COMMENT 'Match number shown on fixtures',
  `status` ENUM('scheduled', 'postponed', 'completed') NOT NULL DEFAULT 'scheduled' COMMENT 'Status of the match',
  `postpone_reason` VARCHAR(255) NULL COMMENT 'Reason the match was postponed',
  PRIMARY KEY (`match_id`),
  CONSTRAINT `fk_matches_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_matches_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`group_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_matches_slot_id` FOREIGN KEY (`slot_id`) REFERENCES `match_slots` (`slot_id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_matches_slot_id` (`slot_id`),
  CONSTRAINT `fk_matches_home_team_id` FOREIGN KEY (`home_team_id`) REFERENCES `teams` (`team_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_matches_away_team_id` FOREIGN KEY (`away_team_id`) REFERENCES `teams` (`team_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_matches_next_match_id` FOREIGN KEY (`next_match_id`) REFERENCES `matches` (`match_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D6 Results and Events: The final score of a match, including extra time and penalties.
CREATE TABLE `match_results` (
  `result_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique result identification number',
  `match_id` INT NOT NULL COMMENT 'Match',
  `home_score` TINYINT(2) NOT NULL COMMENT 'Home goals in normal time',
  `away_score` TINYINT(2) NOT NULL COMMENT 'Away goals in normal time',
  `home_score_et` TINYINT(2) NULL COMMENT 'Home goals after extra time',
  `away_score_et` TINYINT(2) NULL COMMENT 'Away goals after extra time',
  `home_pens` TINYINT(2) NULL COMMENT 'Home penalties scored in the shoot-out',
  `away_pens` TINYINT(2) NULL COMMENT 'Away penalties scored in the shoot-out',
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time the result was saved',
  PRIMARY KEY (`result_id`),
  CONSTRAINT `fk_match_results_match_id` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE RESTRICT,
  UNIQUE KEY `uq_match_results_match_id` (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D6 Results and Events: A goal or card in a match.
CREATE TABLE `match_events` (
  `event_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique event identification number',
  `match_id` INT NOT NULL COMMENT 'Match',
  `team_player_id` INT NOT NULL COMMENT 'Player involved',
  `event_type` ENUM('goal', 'own_goal', 'yellow', 'red') NOT NULL COMMENT 'Type of event',
  `minute` TINYINT(3) NOT NULL COMMENT 'Minute of the event',
  PRIMARY KEY (`event_id`),
  CONSTRAINT `fk_match_events_match_id` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_match_events_team_player_id` FOREIGN KEY (`team_player_id`) REFERENCES `team_players` (`team_player_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D7 Suspensions: A ban caused by a red card or by reaching the yellow card limit.
CREATE TABLE `suspensions` (
  `suspension_id` INT NOT NULL AUTO_INCREMENT COMMENT 'Unique suspension identification number',
  `team_player_id` INT NOT NULL COMMENT 'Suspended player',
  `event_id` INT NOT NULL COMMENT 'Card that caused the ban',
  `reason` ENUM('yellow_limit', 'red_card') NOT NULL COMMENT 'Cause of the ban',
  `matches_banned` TINYINT(1) NOT NULL COMMENT 'Matches the player must miss',
  `matches_served` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Matches already missed',
  `status` ENUM('active', 'served') NOT NULL DEFAULT 'active' COMMENT 'Whether the ban is still active',
  PRIMARY KEY (`suspension_id`),
  CONSTRAINT `fk_suspensions_team_player_id` FOREIGN KEY (`team_player_id`) REFERENCES `team_players` (`team_player_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_suspensions_event_id` FOREIGN KEY (`event_id`) REFERENCES `match_events` (`event_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Default administrator account. Username: admin  Password: admin123
-- Change this password after the first login.
INSERT INTO `users` (`full_name`, `username`, `password_hash`, `role`, `phone`, `must_change_pw`)
VALUES ('Tournament Administrator', 'admin', '$2y$10$74Lbfc3oJ7VlPOvWwpWRouEc.J3UL6dvoEL8O8ZlvKRaimKDH6SNC', 'admin', '0000000000', 0);
