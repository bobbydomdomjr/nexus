-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 23, 2026 at 06:33 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pn_event_registration`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL DEFAULT '',
  `email` varchar(180) NOT NULL DEFAULT '',
  `role` enum('admin','staff','viewer') NOT NULL DEFAULT 'admin',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password`, `name`, `email`, `role`, `status`, `last_login`, `created_at`, `updated_at`) VALUES
(3, 'admin', '$2y$10$bofMQi905rez7eHdG6Vabe4tZnguvhj8YAnSCZXpeNy4znFJtQvc2', 'Administrator', 'admin@company.com', 'admin', 'active', NULL, '2026-01-28 08:16:52', '2026-03-20 17:55:52'),
(4, 'user1', '$2y$12$O5VXkL2eI9xlIipaWJ2V2eAWcCcSOIsf8JVfS1a3v95R17cV7ZQLS', 'User-1', 'user@company.com', 'staff', 'active', NULL, '2026-03-20 08:58:33', '2026-03-21 18:34:06'),
(5, 'hydra -t 4 -V -f -L Usernames/top-usernames-shortl', '$2y$12$5z14xcAjNE9fOiGr4iAspuw1QdKVAVgxby3EryJCyCVF9f/liWIom', 'John Smith', 'johnsmith@nexus.com', 'viewer', 'inactive', NULL, '2026-03-20 12:38:56', '2026-03-22 18:15:42');

-- --------------------------------------------------------

--
-- Table structure for table `event_registrations`
--

CREATE TABLE `event_registrations` (
  `id` int(11) NOT NULL,
  `agenda` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `event_day` int(11) DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `middle_initial` char(1) DEFAULT NULL,
  `ext_name` varchar(20) DEFAULT NULL,
  `unit_office` varchar(150) DEFAULT NULL,
  `rank` varchar(100) DEFAULT NULL,
  `major_service` varchar(100) DEFAULT NULL,
  `serial_number` varchar(50) DEFAULT NULL,
  `designation` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT NULL,
  `agreed_terms` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `event_registrations`
--

INSERT INTO `event_registrations` (`id`, `agenda`, `start_date`, `end_date`, `event_day`, `venue`, `last_name`, `first_name`, `middle_name`, `middle_initial`, `ext_name`, `unit_office`, `rank`, `major_service`, `serial_number`, `designation`, `email`, `contact_number`, `active`, `updated_at`, `agreed_terms`, `created_at`) VALUES
(49, 'YEAR END REVIEW 2026', '2026-03-21', '2026-03-23', 1, 'via Zoom Conference', 'Domdom', 'Bobby', 'Velasco', 'V', 'Jr', 'O/N11', 'Mr', 'PN', '2266', 'Database Administrator', 'bobby.domdomjr1@gmail.com', '09154223266', 1, '2026-03-21 20:52:53', 1, '2026-03-21 10:38:34'),
(50, 'YEAR END REVIEW 2026', '2026-03-21', '2026-03-23', 2, 'via Zoom Conference', 'Dela Cruz', 'Juan', 'Howard', 'H', '', 'O/N11', 'ENL', 'PN', '34324', 'Engineering Officer', 'juan@navy.mil.ph', '09324234234', 1, NULL, 1, '2026-03-21 10:40:29'),
(53, '1ST AFP PROCUREMENT SUMMIT CY 2026', '2026-03-21', '2026-03-22', 2, 'TEJEROS HALL, COMMISSIONED OFFICERS CLUBHOUSE, CGEA QC', 'SMITH', 'JOHN', '', 'R', '', 'O/N2', 'LCDR', 'PN', 'O-92834', 'GDFGFD', 'sample@gmail.com', '09452342342', 1, '2026-03-23 12:54:55', 1, '2026-03-21 11:55:36'),
(54, 'YEAR END REVIEW 2026', '2026-03-21', '2026-03-23', 2, 'via Zoom Conference', 'Mendoza', 'Kaycee', '', 'P', '', 'O/N11', 'Ms', 'CivHR', '2344', 'Researcher', 'kaycee@navy.mil.ph', '09342342343', 1, NULL, 1, '2026-03-21 12:49:40'),
(55, 'YEAR END REVIEW 2026', '2026-03-22', '2026-03-23', 3, 'via Zoom Conference', 'Arieta', 'Levy', '', 'H', '', 'O/N5', 'Mr', 'CivHR', '34523', 'POIC', 'arieta@navy.mil.ph', '09345345353', 1, NULL, 1, '2026-03-22 05:53:57'),
(60, 'YEAR END REVIEW 2026', '2026-03-22', '2026-03-23', 3, NULL, 'Potter', 'Harry', 'Hamburger', 'F', 'Jr', 'ofgdf', 'Mr', 'CivHR', '234', 'sdfsd', 'teset@gmail.com', '987346875', 1, NULL, 0, '2026-03-22 07:19:51'),
(61, 'YEAR END REVIEW 2026', '2026-03-23', '2026-03-24', 4, NULL, 'Dimayuga', 'Princess', 'Aasho', 'A', '', 'MC11', 'Ms', 'CivHR', '3232', 'Researcher', 'dimayuga@navy.mil.ph', '927234234', 1, '2026-03-22 15:26:58', 0, '2026-03-22 07:19:51'),
(64, '3-Day PNSEMIS User\'s Training and Policy Dissemination', '2026-03-23', '2026-03-25', 1, 'Officers Club Naval Station Zamboanga City', 'Dela Cruz', 'Juan', 'San Juan', 'S', '', 'NAVSHIP', 'ENL', 'PN', '847372', 'Engineering Officer', 'juandelacruz@navy.mil.ph', '09847373838', 0, '2026-03-23 11:21:44', 1, '2026-03-23 00:17:05'),
(65, '3-Day PNSEMIS User\'s Training and Policy Dissemination', '2026-03-23', '2026-03-25', 1, 'Officers Club Naval Station Zamboanga City', 'Cuaresma', 'Berlin', 'Rosido', 'R', '', 'O/N11', 'Ms', 'CivHR', '34532', 'Researcher', 'cuaresma@navy.mil.ph1', '09667436734', 1, NULL, 1, '2026-03-23 03:21:07');

-- --------------------------------------------------------

--
-- Table structure for table `event_settings`
--

CREATE TABLE `event_settings` (
  `id` int(11) NOT NULL,
  `agenda` text NOT NULL,
  `venue` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `event_days` int(11) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `event_settings`
--

INSERT INTO `event_settings` (`id`, `agenda`, `venue`, `start_date`, `end_date`, `event_days`, `active`, `updated_at`) VALUES
(80, 'YEAR END REVIEW 2026', 'via Zoom Conference', '2026-03-23', '2026-03-25', 3, 1, '2026-03-23 03:25:57'),
(81, '1ST AFP PROCUREMENT SUMMIT CY 2026', 'TEJEROS HALL, COMMISSIONED OFFICERS CLUBHOUSE, CGEA QC', '2026-03-20', '2026-03-22', 3, 0, '2026-03-23 00:15:30'),
(83, '3-Day PNSEMIS User\'s Training and Policy Dissemination', 'Officers Club Naval Station Zamboanga City', '2026-03-23', '2026-03-25', 3, 1, '2026-03-23 00:14:52');

-- --------------------------------------------------------

--
-- Table structure for table `nexus_notif_read`
--

CREATE TABLE `nexus_notif_read` (
  `admin_id` int(11) NOT NULL,
  `notif_key` varchar(120) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nexus_notif_read`
--

INSERT INTO `nexus_notif_read` (`admin_id`, `notif_key`, `read_at`) VALUES
(3, 'event_80', '2026-03-22 22:46:49'),
(3, 'event_81', '2026-03-22 22:46:46'),
(3, 'event_83', '2026-03-23 08:17:41'),
(3, 'reg_50', '2026-03-22 23:07:27'),
(3, 'reg_51', '2026-03-22 23:07:27'),
(3, 'reg_52', '2026-03-22 23:07:27'),
(3, 'reg_53', '2026-03-22 22:54:07'),
(3, 'reg_54', '2026-03-22 23:07:27'),
(3, 'reg_55', '2026-03-22 23:07:27'),
(3, 'reg_60', '2026-03-22 23:07:27'),
(3, 'reg_61', '2026-03-22 23:07:20'),
(3, 'reg_62', '2026-03-22 22:54:00'),
(3, 'reg_63', '2026-03-22 23:07:27'),
(3, 'reg_64', '2026-03-23 09:19:59'),
(3, 'reg_65', '2026-03-23 11:21:24'),
(3, 'user_3', '2026-03-22 22:46:49'),
(3, 'user_4', '2026-03-22 22:46:49'),
(3, 'user_5', '2026-03-22 22:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `report_queue`
--

CREATE TABLE `report_queue` (
  `id` int(10) UNSIGNED NOT NULL,
  `report_name` varchar(255) NOT NULL,
  `report_type` varchar(100) NOT NULL,
  `event_agenda` varchar(255) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `requested_by` varchar(100) NOT NULL DEFAULT 'admin',
  `output_format` varchar(20) NOT NULL DEFAULT 'PDF',
  `status` enum('Queued','Processing','Ready','Failed') NOT NULL DEFAULT 'Queued',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `report_queue`
--

INSERT INTO `report_queue` (`id`, `report_name`, `report_type`, `event_agenda`, `date_from`, `date_to`, `requested_by`, `output_format`, `status`, `notes`, `created_at`) VALUES
(16, 'Attendance Summary Day 1', 'Attendance', 'YEAR END REVIEW 2026', '2026-03-21', '2026-03-21', 'admin', 'PDF', 'Ready', '', '2026-03-21 18:41:23'),
(17, 'Attendance Summary Day 2', 'Attendance', 'YEAR END REVIEW 2026', '2026-03-22', '2026-03-22', 'admin', 'PDF', 'Ready', '', '2026-03-21 18:42:44'),
(18, 'fghgfh', 'Registration', 'YEAR END REVIEW 2026', '2026-03-21', '2026-03-21', 'admin', 'PDF', 'Queued', '', '2026-03-21 19:01:29'),
(19, 'teeee', 'Registration', 'YEAR END REVIEW 2026', '2026-03-21', '2026-03-21', 'asdsd', 'PDF', 'Failed', '', '2026-03-21 19:11:39'),
(20, 'check', 'Attendance', 'YEAR END REVIEW 2026', '2026-03-23', '2026-03-23', 'asdasd', 'PDF', 'Processing', '', '2026-03-21 19:27:12'),
(21, '1ST AFP PROCUREMENT SUMMIT CY 2026', 'Attendance', '1ST AFP PROCUREMENT SUMMIT CY 2026', '2026-03-22', '2026-03-22', 'dfgdf', 'PDF', 'Processing', '', '2026-03-21 19:59:04'),
(22, 'haha', 'Attendance', 'YEAR END REVIEW 2026', '2026-03-22', '2026-03-22', 'admin', 'Excel', 'Ready', 'test haha', '2026-03-22 14:32:35');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `uq_username` (`username`),
  ADD UNIQUE KEY `uq_email` (`email`);

--
-- Indexes for table `event_registrations`
--
ALTER TABLE `event_registrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `event_settings`
--
ALTER TABLE `event_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `nexus_notif_read`
--
ALTER TABLE `nexus_notif_read`
  ADD PRIMARY KEY (`admin_id`,`notif_key`);

--
-- Indexes for table `report_queue`
--
ALTER TABLE `report_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_report_type` (`report_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_event_agenda` (`event_agenda`(100));

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `event_registrations`
--
ALTER TABLE `event_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `event_settings`
--
ALTER TABLE `event_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `report_queue`
--
ALTER TABLE `report_queue`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
