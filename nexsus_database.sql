-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 09, 2026 at 02:36 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nexsus_demo`
--
CREATE DATABASE IF NOT EXISTS `nexsus_demo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `nexsus_demo`;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `audit_id` bigint(20) UNSIGNED NOT NULL,
  `actor_user_id` int(10) UNSIGNED DEFAULT NULL,
  `action_key` varchar(80) NOT NULL,
  `module_name` varchar(60) NOT NULL,
  `record_id` int(10) UNSIGNED DEFAULT NULL,
  `details` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`audit_id`, `actor_user_id`, `action_key`, `module_name`, `record_id`, `details`, `created_at`) VALUES
(1, 1, 'employee.created', 'employees', 3, 'Workflow activity: employee created.', '2026-10-27 11:09:42'),
(2, 1, 'employee.updated', 'employees', 3, 'Workflow activity: employee updated.', '2026-10-28 18:48:42'),
(3, 1, 'employee.created', 'employees', 4, 'Workflow activity: employee created.', '2026-10-28 21:16:59'),
(4, 1, 'employee.updated', 'employees', 4, 'Workflow activity: employee updated.', '2026-10-28 21:18:08'),
(5, 1, 'service.created', 'services', 1, 'Workflow activity: service created.', '2026-10-29 06:19:08'),
(6, 1, 'service.created', 'services', 2, 'Workflow activity: service created.', '2026-10-29 06:20:14'),
(7, 1, 'service.created', 'services', 3, 'Workflow activity: service created.', '2026-10-29 06:20:55'),
(8, 1, 'service.created', 'services', 4, 'Workflow activity: service created.', '2026-10-29 06:22:04'),
(9, 1, 'service.created', 'services', 5, 'Workflow activity: service created.', '2026-10-29 06:23:01'),
(10, 1, 'service.created', 'services', 6, 'Workflow activity: service created.', '2026-10-29 06:23:22'),
(11, 1, 'service_request.created', 'service_requests', 1, 'Workflow activity: service_request created.', '2026-10-29 06:59:53'),
(12, 1, 'service.updated', 'services', 5, 'Workflow activity: service updated.', '2026-10-29 07:13:04'),
(13, 1, 'service.created', 'services', 7, 'Workflow activity: service created.', '2026-10-29 07:21:53'),
(14, 1, 'backup.created', 'settings', 1, 'Workflow activity: backup created.', '2026-10-29 09:09:01'),
(15, 1, 'task.created', 'tasks', 1, 'Workflow activity: task created.', '2026-10-29 10:05:14'),
(16, 1, 'employee.created', 'employees', 5, 'Workflow activity: employee created.', '2026-10-29 14:28:26'),
(17, 1, 'employee.updated', 'employees', 4, 'Workflow activity: employee updated.', '2026-10-29 15:23:02'),
(18, 1, 'backup.created', 'settings', 2, 'Workflow activity: backup created.', '2026-10-30 13:49:06'),
(19, 1, 'task.created', 'tasks', 2, 'Workflow activity: task created.', '2026-10-30 15:03:49'),
(20, 1, 'backup.created', 'settings', 3, 'Workflow activity: backup created.', '2026-11-01 09:18:09'),
(21, 1, 'report.print', 'reports', 1, 'Workflow activity: report print.', '2026-11-01 09:24:56'),
(22, 1, 'report.csv', 'reports', 2, 'Workflow activity: report csv.', '2026-11-02 11:03:05'),
(23, 1, 'report.print', 'reports', 3, 'Workflow activity: report print.', '2026-11-02 11:03:13'),
(24, 1, 'task.created', 'tasks', 3, 'Workflow activity: task created.', '2026-11-02 11:06:05'),
(25, 1, 'employee.created', 'employees', 6, 'Workflow activity: employee created.', '2026-11-02 11:18:52'),
(26, 1, 'backup.created', 'settings', 4, 'Workflow activity: backup created.', '2026-11-02 11:19:31'),
(27, 1, 'employee.created', 'employees', 7, 'Workflow activity: employee created.', '2026-11-02 11:37:59'),
(28, 1, 'employee.status.deactivated', 'employees', 5, 'Workflow activity: employee status deactivated.', '2026-11-02 11:39:06'),
(29, 1, 'employee.status.active', 'employees', 5, 'Workflow activity: employee status active.', '2026-11-02 11:39:17'),
(30, 1, 'task.created', 'tasks', 4, 'Workflow activity: task created.', '2026-11-02 12:20:28'),
(31, 1, 'service_request.created', 'service_requests', 2, 'Workflow activity: service_request created.', '2026-11-02 12:21:42'),
(32, 1, 'employee.updated', 'employees', 4, 'Workflow activity: employee updated.', '2026-11-02 22:01:07'),
(33, 1, 'document.uploaded', 'documents', 1, 'Workflow activity: document uploaded.', '2026-11-02 22:13:05'),
(34, 3, 'task.completed', 'tasks', 3, 'Workflow activity: task completed.', '2026-11-02 22:34:42'),
(35, 1, 'task.updated', 'tasks', 1, 'Workflow activity: task updated.', '2026-11-02 22:37:43'),
(36, 2, 'task.completed', 'tasks', 1, 'Workflow activity: task completed.', '2026-11-02 22:42:53'),
(37, 2, 'task.completed', 'tasks', 2, 'Workflow activity: task completed.', '2026-11-02 22:45:54'),
(38, 1, 'service_request.created', 'service_requests', 3, 'Workflow activity: service_request created.', '2026-11-02 22:50:08'),
(39, 1, 'employee.updated', 'employees', 5, 'Workflow activity: employee updated.', '2026-11-03 07:54:29'),
(40, 1, 'employee.status.deactivated', 'employees', 5, 'Workflow activity: employee status deactivated.', '2026-11-03 07:54:41'),
(41, 1, 'service_request.created', 'service_requests', 4, 'Workflow activity: service_request created.', '2026-11-03 08:53:03'),
(42, 1, 'service.updated', 'services', 1, 'Workflow activity: service updated.', '2026-11-03 09:42:41'),
(43, 1, 'service_request.created', 'service_requests', 5, 'Workflow activity: service_request created.', '2026-11-03 09:46:38'),
(44, 1, 'service_request.updated', 'service_requests', 5, 'Workflow activity: service_request updated.', '2026-11-03 09:47:49'),
(45, 1, 'service_request.updated', 'service_requests', 4, 'Workflow activity: service_request updated.', '2026-11-03 09:48:08'),
(46, 1, 'service_request.duplicate_removed', 'service_requests', 4, 'Workflow activity: service_request duplicate_removed.', '2026-11-03 10:22:20'),
(47, 1, 'report.csv', 'reports', 4, 'Workflow activity: report csv.', '2026-11-03 10:28:47'),
(48, 1, 'report.print', 'reports', 5, 'Workflow activity: report print.', '2026-11-03 10:47:19'),
(49, 1, 'report.print', 'reports', 6, 'Workflow activity: report print.', '2026-11-03 12:12:09'),
(50, 1, 'backup.created', 'settings', 5, 'Workflow activity: backup created.', '2026-11-03 12:47:47'),
(51, 1, 'task.updated', 'tasks', 4, 'Workflow activity: task updated.', '2026-11-03 13:13:23'),
(52, 2, 'document.uploaded', 'documents', 2, 'Workflow activity: document uploaded.', '2026-11-03 13:14:58'),
(53, 2, 'task.completed', 'tasks', 4, 'Workflow activity: task completed.', '2026-11-03 13:15:36'),
(54, 1, 'task.created', 'tasks', 5, 'Workflow activity: task created.', '2026-11-03 13:29:15'),
(55, 6, 'document.uploaded', 'documents', 3, 'Workflow activity: document uploaded.', '2026-11-03 13:32:23'),
(56, 6, 'task.progress', 'tasks', 5, 'Workflow activity: task progress.', '2026-11-03 13:33:54'),
(57, 6, 'task.completed', 'tasks', 5, 'Workflow activity: task completed.', '2026-11-03 13:34:43'),
(58, 1, 'service_request.status.in_progress', 'service_requests', 3, 'Workflow activity: service_request status in_progress.', '2026-11-03 13:35:57'),
(59, 1, 'service.created', 'services', 8, 'Workflow activity: service created.', '2026-11-03 18:32:33'),
(60, 1, 'service.created', 'services', 9, 'Workflow activity: service created.', '2026-11-03 18:35:58'),
(61, 1, 'service_request.updated', 'service_requests', 3, 'Workflow activity: service_request updated.', '2026-11-04 08:31:20'),
(62, 1, 'service_request.status.completed', 'service_requests', 3, 'Workflow activity: service_request status completed.', '2026-11-04 08:32:01'),
(63, 1, 'service_request.updated', 'service_requests', 1, 'Workflow activity: service_request updated.', '2026-11-04 08:32:11'),
(64, 1, 'service_request.updated', 'service_requests', 5, 'Workflow activity: service_request updated.', '2026-11-04 08:32:46'),
(65, 1, 'service_request.status.cancelled', 'service_requests', 1, 'Workflow activity: service_request status cancelled.', '2026-11-04 08:33:21'),
(66, 1, 'service_request.updated', 'service_requests', 5, 'Workflow activity: service_request updated.', '2026-11-04 08:46:13'),
(67, 1, 'service_request.duplicate_removed', 'service_requests', 5, 'Workflow activity: service_request duplicate_removed.', '2026-11-04 08:46:42'),
(68, 1, 'service_request.status.cancelled', 'service_requests', 2, 'Workflow activity: service_request status cancelled.', '2026-11-04 08:47:37'),
(69, 1, 'service_request.created', 'service_requests', 6, 'Workflow activity: service_request created.', '2026-11-04 08:57:23'),
(70, 1, 'task.created', 'tasks', 6, 'Workflow activity: task created.', '2026-11-04 09:00:26'),
(71, 1, 'task.created', 'tasks', 7, 'Workflow activity: task created.', '2026-11-04 09:02:02'),
(72, 2, 'task.completed', 'tasks', 6, 'Workflow activity: task completed.', '2026-11-04 09:06:49'),
(73, 1, 'report.print', 'reports', 7, 'Workflow activity: report print.', '2026-11-04 09:10:18'),
(74, 1, 'report.print', 'reports', 8, 'Workflow activity: report print.', '2026-11-04 09:11:07'),
(75, 1, 'task.updated', 'tasks', 7, 'Workflow activity: task updated.', '2026-11-04 09:14:26'),
(76, 1, 'service.created', 'services', 10, 'Workflow activity: service created.', '2026-11-04 09:20:56'),
(77, 1, 'service.created', 'services', 11, 'Workflow activity: service created.', '2026-11-04 09:43:52'),
(78, 1, 'service.created', 'services', 12, 'Workflow activity: service created.', '2026-11-04 09:44:25'),
(79, 1, 'service.created', 'services', 13, 'Workflow activity: service created.', '2026-11-04 09:45:10'),
(80, 1, 'service.created', 'services', 14, 'Workflow activity: service created.', '2026-11-04 09:46:09'),
(81, 1, 'backup.created', 'settings', 1, 'Workflow activity: backup created.', '2026-11-04 14:00:56'),
(82, 1, 'backup.downloaded', 'settings', 1, 'Workflow activity: backup downloaded.', '2026-11-04 14:01:01'),
(83, 1, 'backup.created', 'settings', 1, 'Workflow activity: backup created.', '2026-11-04 20:01:58'),
(84, 1, 'backup.downloaded', 'settings', 1, 'Workflow activity: backup downloaded.', '2026-11-04 20:02:26'),
(85, 1, 'service_request.created', 'service_requests', 7, 'Workflow activity: service_request created.', '2026-11-04 20:41:49'),
(86, 1, 'service_request.created', 'service_requests', 8, 'Workflow activity: service_request created.', '2026-11-04 22:04:01'),
(87, 1, 'task.created', 'tasks', 9, 'Workflow activity: task created.', '2026-11-04 22:05:52'),
(88, 1, 'service.created', 'services', 15, 'Workflow activity: service created.', '2026-11-04 23:10:41'),
(89, 1, 'employee.status.deactivated', 'employees', 7, 'Workflow activity: employee status deactivated.', '2026-11-04 23:12:02'),
(90, 1, 'service_request.created', 'service_requests', 9, 'Workflow activity: service_request created.', '2026-11-05 00:09:08'),
(91, 1, 'service_request.updated', 'service_requests', 9, 'Workflow activity: service_request updated.', '2026-11-05 01:59:01'),
(92, 1, 'service_request.status.in_progress', 'service_requests', 9, 'Workflow activity: service_request status in_progress.', '2026-11-05 01:59:09'),
(93, 1, 'task.created', 'tasks', 10, 'Workflow activity: task created.', '2026-11-05 02:16:31'),
(94, 1, 'service_request.created', 'service_requests', 10, 'Workflow activity: service_request created.', '2026-11-05 05:31:56'),
(95, 1, 'service_request.status.in_progress', 'service_requests', 10, 'Workflow activity: service_request status in_progress.', '2026-11-05 05:32:01'),
(96, 1, 'service.updated', 'services', 5, 'Workflow activity: service updated.', '2026-11-05 05:35:05'),
(97, 1, 'task.created', 'tasks', 11, 'Workflow activity: task created.', '2026-11-05 05:36:24'),
(98, 6, 'task.progress', 'tasks', 11, 'Workflow activity: task progress.', '2026-11-05 05:37:56'),
(99, 6, 'task.completed', 'tasks', 11, 'Workflow activity: task completed.', '2026-11-05 05:39:03'),
(100, 3, 'document.uploaded', 'documents', 4, 'Workflow activity: document uploaded.', '2026-11-05 05:43:40'),
(101, 3, 'task.completed', 'tasks', 10, 'Workflow activity: task completed.', '2026-11-05 05:44:38'),
(102, 1, 'employee.created', 'employees', 8, 'Workflow activity: employee created.', '2026-11-05 10:55:48'),
(103, 1, 'service_request.created', 'service_requests', 11, 'Workflow activity: service_request created.', '2026-11-05 11:11:51'),
(104, 1, 'task.created', 'tasks', 12, 'Workflow activity: task created.', '2026-11-05 11:13:12'),
(105, 2, 'task.completed', 'tasks', 12, 'Workflow activity: task completed.', '2026-11-05 11:24:43'),
(106, 2, 'document.uploaded', 'documents', 5, 'Workflow activity: document uploaded.', '2026-11-05 11:29:20'),
(107, 1, 'report.csv', 'reports', 9, 'Workflow activity: report csv.', '2026-11-05 11:37:41'),
(108, 1, 'report.print', 'reports', 10, 'Workflow activity: report print.', '2026-11-05 11:37:44'),
(109, 1, 'report.print', 'reports', 11, 'Workflow activity: report print.', '2026-11-05 11:38:13'),
(110, 1, 'report.print', 'reports', 12, 'Workflow activity: report print.', '2026-11-05 11:38:42'),
(111, 1, 'backup.created', 'settings', 1, 'Workflow activity: backup created.', '2026-11-05 11:42:03'),
(112, 1, 'backup.created', 'settings', 1, 'Workflow activity: backup created.', '2026-11-05 11:42:07'),
(113, 1, 'backup.downloaded', 'settings', 1, 'Workflow activity: backup downloaded.', '2026-11-05 11:42:25'),
(114, 2, 'service_request.status.in_progress', 'service_requests', 11, 'Workflow activity: service_request status in_progress.', '2026-11-06 13:17:35'),
(115, 2, 'service_request.status.in_progress', 'service_requests', 11, 'Workflow activity: service_request status in_progress.', '2026-11-06 13:17:42'),
(116, 1, 'service_request.status.completed', 'service_requests', 11, 'Workflow activity: service_request status completed.', '2026-11-07 10:25:46'),
(117, 1, 'document.downloaded', 'documents', 5, 'Workflow activity: document downloaded.', '2026-11-07 11:24:10'),
(118, 1, 'service.updated', 'services', 8, 'Workflow activity: service updated.', '2026-11-07 11:25:05'),
(119, 1, 'employee.created', 'employees', 9, 'Workflow activity: employee created.', '2026-11-08 16:00:44'),
(120, 1, 'service_request.created', 'service_requests', 12, 'Workflow activity: service_request created.', '2026-11-08 16:56:18'),
(121, 1, 'task.created', 'tasks', 13, 'Workflow activity: task created.', '2026-11-08 16:58:31'),
(122, 1, 'service_request.updated', 'service_requests', 12, 'Workflow activity: service_request updated.', '2026-11-08 16:59:35'),
(123, 1, 'task.updated', 'tasks', 13, 'Workflow activity: task updated.', '2026-11-08 17:00:15'),
(124, 9, 'task.progress', 'tasks', 13, 'Workflow activity: task progress.', '2026-11-08 17:36:58'),
(125, 9, 'task.progress', 'tasks', 13, 'Workflow activity: task progress.', '2026-11-08 17:38:12'),
(126, 9, 'task.completed', 'tasks', 13, 'Workflow activity: task completed.', '2026-11-08 17:42:40'),
(127, 1, 'service_request.status.completed', 'service_requests', 9, 'Workflow activity: service_request status completed.', '2026-11-08 23:25:50'),
(128, 2, 'task.completed', 'tasks', 9, 'Workflow activity: task completed.', '2026-11-08 23:27:03'),
(129, 1, 'service_request.status.cancelled', 'service_requests', 8, 'Workflow activity: service_request status cancelled.', '2026-11-08 23:27:51'),
(130, 1, 'report.csv', 'reports', 13, 'Workflow activity: report csv.', '2026-11-08 23:41:26'),
(131, 1, 'report.print', 'reports', 14, 'Workflow activity: report print.', '2026-11-08 23:42:49'),
(132, 1, 'backup.created', 'settings', 1, 'Workflow activity: backup created.', '2026-11-08 23:50:35'),
(133, 1, 'backup.downloaded', 'settings', 1, 'Workflow activity: backup downloaded.', '2026-11-08 23:51:06'),
(134, 1, 'backup.downloaded', 'settings', 1, 'Workflow activity: backup downloaded.', '2026-11-08 23:51:19'),
(135, 1, 'backup.downloaded', 'settings', 1, 'Workflow activity: backup downloaded.', '2026-11-08 23:51:28'),
(136, 1, 'employee.created', 'employees', 10, 'Employee account management action', '2026-11-09 14:48:23');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  `client_id` int(10) UNSIGNED NOT NULL,
  `client_type` enum('individual','business') NOT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `contact_position` varchar(100) DEFAULT NULL,
  `business_name` varchar(150) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `preferred_contact_method` enum('phone','whatsapp','sms','email') NOT NULL DEFAULT 'phone',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`client_id`, `client_type`, `first_name`, `middle_name`, `last_name`, `contact_position`, `business_name`, `email`, `phone_number`, `preferred_contact_method`, `status`, `created_at`, `updated_at`) VALUES
(1, 'individual', 'Anika', NULL, 'Reddy', NULL, NULL, 'anika.reddy.client@gmail.test', '0000002001', 'phone', 'active', '2026-10-29 06:58:18', '2026-10-29 06:58:18'),
(2, 'individual', 'Ethan', NULL, 'Jacobs', NULL, NULL, 'ethan.jacobs.client@gmail.test', '0000002002', 'phone', 'active', '2026-10-29 13:14:53', '2026-11-06 13:20:03'),
(3, 'individual', 'Lerato', NULL, 'Maseko', NULL, NULL, 'lerato.maseko.client@gmail.test', '0000002003', 'phone', 'active', '2026-11-02 12:23:49', '2026-11-02 12:23:49'),
(4, 'business', 'Naledi', NULL, 'Mokoena', 'Owner', 'Seabrook Books', 'naledi.mokoena.client@gmail.test', '0000002004', 'email', 'active', '2026-11-03 08:51:58', '2026-11-03 08:51:58'),
(5, 'business', 'Arjun', NULL, 'Singh', 'Owner', 'Harbourlight Logistics', 'arjun.singh.client@gmail.test', '0000002005', 'whatsapp', 'active', '2026-11-04 08:55:56', '2026-11-04 08:55:56'),
(6, 'business', 'Farah', NULL, 'Patel', 'Owner', 'Oakridge Trading', 'farah.patel.client@gmail.test', '0000002006', 'phone', 'active', '2026-11-04 09:30:13', '2026-11-04 09:30:13'),
(7, 'business', 'Theo', NULL, 'Dube', 'Owner', 'Summit Retail', 'theo.dube.client@gmail.test', '0000002007', 'phone', 'active', '2026-11-04 20:41:00', '2026-11-04 20:41:00'),
(8, 'individual', 'Maya', NULL, 'Pillay', NULL, NULL, 'maya.pillay.client@gmail.test', '0000002008', 'email', 'active', '2026-11-04 22:03:29', '2026-11-04 22:03:29'),
(9, 'individual', 'Aiden', NULL, 'Dlamini', NULL, NULL, 'aiden.dlamini.client@gmail.test', '0000002009', 'phone', 'inactive', '2026-11-05 11:08:43', '2026-11-08 22:57:37');

-- --------------------------------------------------------

--
-- Table structure for table `contact_requests`
--

DROP TABLE IF EXISTS `contact_requests`;
CREATE TABLE `contact_requests` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(254) NOT NULL,
  `phone` varchar(25) NOT NULL,
  `preferred_contact` enum('phone','email') NOT NULL,
  `message` text NOT NULL,
  `status` enum('new','in_progress','completed') NOT NULL DEFAULT 'new',
  `handled_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `contact_requests`
--

INSERT INTO `contact_requests` (`id`, `name`, `email`, `phone`, `preferred_contact`, `message`, `status`, `handled_by`, `created_at`) VALUES
(2, 'Nolwazi Mthembu', 'nolwazi.mthembu.enquiry@gmail.test', '0000003002', 'phone', 'Good day, please advise on your available business services. Enquiry reference 1.', 'in_progress', NULL, '2026-10-29 04:58:48'),
(5, 'Adrian Daniels', 'adrian.daniels.enquiry@gmail.test', '0000003005', 'phone', 'Good day, please advise on your available business services. Enquiry reference 2.', 'new', NULL, '2026-10-29 11:14:11'),
(6, 'Samira Khan', 'samira.khan.enquiry@gmail.test', '0000003006', 'email', 'Good day, please advise on your available business services. Enquiry reference 3.', 'in_progress', NULL, '2026-10-29 18:50:53'),
(8, 'Lesedi Sibiya', 'lesedi.sibiya.enquiry@gmail.test', '0000003008', 'email', 'Good day, please advise on your available business services. Enquiry reference 4.', 'completed', NULL, '2026-11-04 17:54:20'),
(9, 'Jayden Peters', 'jayden.peters.enquiry@gmail.test', '0000003009', 'email', 'Good day, please advise on your available business services. Enquiry reference 5.', 'in_progress', NULL, '2026-11-05 07:04:09');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `document_id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `service_request_id` int(10) UNSIGNED DEFAULT NULL,
  `task_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `category` enum('identification','taxation','payroll','accounting','banking','contract','other') NOT NULL DEFAULT 'other',
  `is_confidential` tinyint(1) NOT NULL DEFAULT 0,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `storage_path` varchar(500) NOT NULL,
  `file_extension` varchar(10) NOT NULL,
  `mime_type` varchar(150) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `file_hash` char(64) NOT NULL,
  `version_number` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `previous_document_id` int(10) UNSIGNED DEFAULT NULL,
  `document_status` enum('active','archived','replaced') NOT NULL DEFAULT 'active',
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`document_id`, `client_id`, `service_request_id`, `task_id`, `title`, `description`, `category`, `is_confidential`, `original_name`, `stored_name`, `storage_path`, `file_extension`, `mime_type`, `file_size`, `file_hash`, `version_number`, `previous_document_id`, `document_status`, `uploaded_by`, `archived_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 3, 'Identification review document', 'Sample supporting document for the client request.', 'other', 0, 'demo_document_01.jpg', 'demo_document_01.jpg', 'demo_document_01.jpg', 'jpg', 'image/jpeg', 47061, 'a336da6395bfad25d04ddc3a9dfea6cddf9c89f6b6c16521f80dc1a191d95a1d', 1, NULL, 'active', 1, NULL, '2026-11-02 22:13:05', '2026-11-02 22:13:05'),
(2, 1, 1, 4, 'Bookkeeping reference document', 'Sample supporting document for the client request.', 'other', 0, 'demo_document_02.jpg', 'demo_document_02.jpg', 'demo_document_02.jpg', 'jpg', 'image/jpeg', 47181, '809e905dced48a390ca14c7504f033553712df1ee8f99d3bf175074a3cd11c2c', 1, NULL, 'active', 2, NULL, '2026-11-03 13:14:58', '2026-11-03 13:14:58'),
(3, 2, 2, 5, 'Taxation supporting document', 'Sample supporting document for the client request.', 'other', 0, 'demo_document_03.jpg', 'demo_document_03.jpg', 'demo_document_03.jpg', 'jpg', 'image/jpeg', 47220, 'bab4aaaef9c371558dc2b86937e88b0e142e7d31a199bc61daac4198a41f5912', 1, NULL, 'active', 6, NULL, '2026-11-03 13:32:23', '2026-11-03 13:32:23'),
(4, 8, 9, 10, 'Payroll supporting document', 'Sample supporting document for the client request.', 'other', 0, 'demo_document_04.jpg', 'demo_document_04.jpg', 'demo_document_04.jpg', 'jpg', 'image/jpeg', 47138, '9f0267b20dde0aeab9fa9f53d8099ed8b566bba45f9148abed5292e7371a20bf', 1, NULL, 'active', 3, NULL, '2026-11-05 05:43:40', '2026-11-05 05:43:40'),
(5, 8, 8, 9, 'Bank reconciliation document', 'Sample supporting document for the client request.', 'other', 0, 'demo_document_05.jpg', 'demo_document_05.jpg', 'demo_document_05.jpg', 'jpg', 'image/jpeg', 47145, '84b65268b229ab98fe6c3e796810296b4f5efc6f47ca4ded54dc3a332d0cf685', 1, NULL, 'active', 2, NULL, '2026-11-05 11:29:20', '2026-11-05 11:29:20');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `notification_type` enum('task_assigned','task_completed','deadline_upcoming','task_overdue','contact_request','document_uploaded','other') NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` varchar(500) NOT NULL,
  `reference_type` varchar(40) DEFAULT NULL,
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `notification_type`, `title`, `message`, `reference_type`, `reference_id`, `read_at`, `created_at`) VALUES
(1, 2, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 1, '2026-11-04 11:49:33', '2026-10-29 10:05:14'),
(2, 2, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 2, '2026-11-04 11:49:33', '2026-10-30 15:03:49'),
(3, 1, 'task_overdue', 'Demo task overdue', 'A fictional task is overdue.', 'task', 2, '2026-11-03 12:16:56', '2026-11-02 07:41:56'),
(4, 1, 'task_overdue', 'Demo task overdue', 'A fictional task is overdue.', 'task', 1, '2026-11-04 08:48:55', '2026-11-02 07:41:56'),
(6, 1, 'contact_request', 'New demo enquiry', 'A fictional contact enquiry was submitted.', 'contact_request', 3, '2026-11-04 08:48:55', '2026-11-02 07:41:56'),
(9, 3, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 3, NULL, '2026-11-02 11:06:05'),
(10, 1, 'contact_request', 'New demo enquiry', 'A fictional contact enquiry was submitted.', 'contact_request', 7, '2026-11-04 08:48:55', '2026-11-02 11:14:06'),
(11, 4, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 4, NULL, '2026-11-02 12:20:28'),
(12, 3, 'document_uploaded', 'Demo document uploaded', 'A fictional sample document was added.', 'document', 1, NULL, '2026-11-02 22:13:05'),
(13, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 3, '2026-11-02 22:38:25', '2026-11-02 22:34:42'),
(14, 2, 'task_overdue', 'Demo task overdue', 'A fictional task is overdue.', 'task', 2, '2026-11-04 11:49:33', '2026-11-02 22:40:07'),
(15, 2, 'task_overdue', 'Demo task overdue', 'A fictional task is overdue.', 'task', 1, '2026-11-04 11:49:33', '2026-11-02 22:40:07'),
(17, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 1, '2026-11-02 22:50:49', '2026-11-02 22:42:53'),
(18, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 2, '2026-11-02 22:50:45', '2026-11-02 22:45:54'),
(19, 2, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 4, '2026-11-04 11:49:33', '2026-11-03 13:13:23'),
(20, 1, 'document_uploaded', 'Demo document uploaded', 'A fictional sample document was added.', 'document', 2, '2026-11-04 08:48:55', '2026-11-03 13:14:58'),
(21, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 4, '2026-11-04 08:48:55', '2026-11-03 13:15:36'),
(22, 6, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 5, '2026-11-04 08:22:29', '2026-11-03 13:29:15'),
(23, 1, 'document_uploaded', 'Demo document uploaded', 'A fictional sample document was added.', 'document', 3, '2026-11-04 08:48:55', '2026-11-03 13:32:23'),
(24, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 5, '2026-11-04 08:48:55', '2026-11-03 13:34:43'),
(25, 1, 'contact_request', 'New demo enquiry', 'A fictional contact enquiry was submitted.', 'contact_request', 5, '2026-11-04 08:48:55', '2026-11-04 08:40:24'),
(26, 2, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 6, '2026-11-04 11:49:33', '2026-11-04 09:00:26'),
(27, 2, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 7, '2026-11-04 11:49:33', '2026-11-04 09:02:02'),
(28, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 6, NULL, '2026-11-04 09:06:49'),
(29, 1, 'task_overdue', 'Demo task overdue', 'A fictional task is overdue.', 'task', 8, NULL, '2026-11-04 18:26:59'),
(30, 2, 'task_overdue', 'Demo task overdue', 'A fictional task is overdue.', 'task', 8, NULL, '2026-11-04 18:27:54'),
(31, 1, 'contact_request', 'New demo enquiry', 'A fictional contact enquiry was submitted.', 'contact_request', 8, NULL, '2026-11-04 22:00:59'),
(32, 2, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 9, NULL, '2026-11-04 22:05:52'),
(33, 3, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 10, NULL, '2026-11-05 02:16:31'),
(34, 6, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 11, NULL, '2026-11-05 05:36:24'),
(35, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 11, NULL, '2026-11-05 05:39:03'),
(36, 1, 'document_uploaded', 'Demo document uploaded', 'A fictional sample document was added.', 'document', 4, NULL, '2026-11-05 05:43:40'),
(37, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 10, NULL, '2026-11-05 05:44:38'),
(38, 1, 'contact_request', 'New demo enquiry', 'A fictional contact enquiry was submitted.', 'contact_request', 9, '2026-11-05 11:05:11', '2026-11-05 11:04:38'),
(39, 2, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 12, '2026-11-05 11:29:57', '2026-11-05 11:13:12'),
(40, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 12, NULL, '2026-11-05 11:24:43'),
(41, 1, 'document_uploaded', 'Demo document uploaded', 'A fictional sample document was added.', 'document', 5, NULL, '2026-11-05 11:29:20'),
(42, 8, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 13, NULL, '2026-11-08 16:58:31'),
(43, 9, 'task_assigned', 'Demo task assigned', 'A fictional task was assigned for testing.', 'task', 13, NULL, '2026-11-08 17:00:15'),
(44, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 13, NULL, '2026-11-08 17:42:40'),
(45, 1, 'task_completed', 'Demo task completed', 'A fictional task was marked completed.', 'task', 9, NULL, '2026-11-08 23:27:03');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `reset_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_exports`
--

DROP TABLE IF EXISTS `report_exports`;
CREATE TABLE `report_exports` (
  `report_id` bigint(20) UNSIGNED NOT NULL,
  `report_type` enum('task_progress','employee_workload','service_performance','deadline_monitoring') NOT NULL,
  `generated_by` int(10) UNSIGNED NOT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `output_format` enum('csv','print') NOT NULL DEFAULT 'csv',
  `generated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `report_exports`
--

INSERT INTO `report_exports` (`report_id`, `report_type`, `generated_by`, `date_from`, `date_to`, `file_path`, `output_format`, `generated_at`) VALUES
(1, 'employee_workload', 1, NULL, NULL, NULL, 'print', '2026-11-01 09:24:56'),
(2, 'task_progress', 1, NULL, NULL, NULL, 'csv', '2026-11-02 11:03:05'),
(3, 'task_progress', 1, NULL, NULL, NULL, 'print', '2026-11-02 11:03:13'),
(4, 'task_progress', 1, NULL, NULL, NULL, 'csv', '2026-11-03 10:28:47'),
(5, 'task_progress', 1, NULL, NULL, NULL, 'print', '2026-11-03 10:47:19'),
(6, 'task_progress', 1, NULL, NULL, NULL, 'print', '2026-11-03 12:12:09'),
(7, 'service_performance', 1, NULL, '2026-11-05', NULL, 'print', '2026-11-04 09:10:18'),
(8, 'task_progress', 1, '2026-10-30', '2026-11-04', NULL, 'print', '2026-11-04 09:11:07'),
(9, 'task_progress', 1, NULL, NULL, NULL, 'csv', '2026-11-05 11:37:41'),
(10, 'task_progress', 1, NULL, NULL, NULL, 'print', '2026-11-05 11:37:44'),
(11, 'task_progress', 1, NULL, NULL, NULL, 'print', '2026-11-05 11:38:13'),
(12, 'task_progress', 1, NULL, NULL, NULL, 'print', '2026-11-05 11:38:42'),
(13, 'task_progress', 1, '2026-11-04', '2026-11-09', NULL, 'csv', '2026-11-08 23:41:26'),
(14, 'task_progress', 1, '2026-11-04', '2026-11-09', NULL, 'print', '2026-11-08 23:42:49');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
  `service_id` int(10) UNSIGNED NOT NULL,
  `service_name` varchar(120) NOT NULL,
  `category` varchar(60) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `service_name`, `category`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Monthly Bookkeeping', 'accounting', 'Recording and maintaining client financial transactions.', 'active', '2026-10-29 06:19:08', '2026-11-03 09:42:41'),
(2, 'Bank Reconciliation', 'accounting', 'Comparing bank statements with accounting records.', 'active', '2026-10-29 06:20:14', '2026-10-29 06:20:14'),
(3, 'Personal Income Tax Return', 'taxation', 'Preparing and submitting individual income tax returns.', 'active', '2026-10-29 06:20:55', '2026-10-29 06:20:55'),
(4, 'Business Income Tax Return', 'taxation', 'Preparing and submitting company income tax returns.', 'active', '2026-10-29 06:22:04', '2026-10-29 06:22:04'),
(5, 'SARS eFiling Submission', 'taxation', 'Submitting applicable tax returns and documents through SARS eFiling.', 'active', '2026-10-29 06:23:01', '2026-11-05 05:35:05'),
(6, 'Payslip Preparation', 'payroll', 'Generating monthly employee payslips.', 'active', '2026-10-29 06:23:22', '2026-10-29 06:23:22'),
(7, 'VAT Return Preparation', 'taxation', NULL, 'active', '2026-10-29 07:21:53', '2026-10-29 07:21:53'),
(8, 'Monthly Payroll Processing', 'payroll', NULL, 'inactive', '2026-11-03 18:32:33', '2026-11-07 11:25:05'),
(9, 'Tax Compliance Status Review', 'taxation', NULL, 'active', '2026-11-03 18:35:58', '2026-11-03 18:35:58'),
(10, 'Cash Flow Reporting', 'accounting', NULL, 'active', '2026-11-04 09:20:56', '2026-11-04 09:20:56'),
(11, 'EMP201 Preparation', 'payroll', 'Registered employers', 'active', '2026-11-04 09:43:52', '2026-11-04 09:43:52'),
(12, 'IT3(a) Certificate Preparation', 'payroll', 'Verify employee', 'active', '2026-11-04 09:44:25', '2026-11-04 09:44:25'),
(13, 'Provisional Tax Return', 'taxation', 'Companies, qualifying individuals', 'active', '2026-11-04 09:45:10', '2026-11-04 09:45:10'),
(14, 'SARS Correspondence Assistance', 'taxation', 'SARS Correspondence Assistance -review SARS notice', 'active', '2026-11-04 09:46:09', '2026-11-04 09:46:09'),
(15, 'Payroll processing', 'payroll', NULL, 'active', '2026-11-04 23:10:41', '2026-11-04 23:10:41');

-- --------------------------------------------------------

--
-- Table structure for table `service_requests`
--

DROP TABLE IF EXISTS `service_requests`;
CREATE TABLE `service_requests` (
  `service_request_id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(10) UNSIGNED NOT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `request_title` varchar(180) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `request_status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `start_date` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_requests`
--

INSERT INTO `service_requests` (`service_request_id`, `client_id`, `service_id`, `assigned_to`, `priority`, `request_title`, `description`, `request_status`, `start_date`, `due_date`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 3, 3, 'high', 'Personal Income Tax Return', 'Client requested the listed service and the owner assigned a lead employee.', 'cancelled', '2026-10-29', '2026-11-04', 1, '2026-10-29 06:59:53', '2026-11-04 08:33:21'),
(2, 2, 3, 4, 'medium', 'Personal Income Tax Return', 'Client requested the listed service and the owner assigned a lead employee.', 'cancelled', '2026-11-02', '2026-11-11', 1, '2026-11-02 12:21:42', '2026-11-04 08:47:37'),
(3, 3, 6, 6, 'high', 'Payslip Preparation', 'Client requested the listed service and the owner assigned a lead employee.', 'completed', '2026-11-02', '2026-11-03', 1, '2026-11-02 22:50:08', '2026-11-04 08:32:01'),
(4, 4, 1, 6, 'high', 'Monthly Bookkeeping', 'Client requested the listed service and the owner assigned a lead employee.', 'cancelled', '2026-11-03', '2026-11-05', 1, '2026-11-03 08:53:03', '2026-11-03 10:22:20'),
(5, 4, 1, 6, 'high', 'Monthly Bookkeeping', 'Client requested the listed service and the owner assigned a lead employee.', 'cancelled', '2026-11-03', '2026-11-05', 1, '2026-11-03 09:46:38', '2026-11-04 08:46:42'),
(6, 5, 1, 2, 'medium', 'Monthly Bookkeeping', 'Client requested the listed service and the owner assigned a lead employee.', 'pending', '2026-11-04', '2026-11-13', 1, '2026-11-04 08:57:23', '2026-11-04 08:57:23'),
(7, 7, 8, 2, 'medium', 'Monthly Payroll Processing', 'Client requested the listed service and the owner assigned a lead employee.', 'pending', '2026-11-04', '2026-11-16', 1, '2026-11-04 20:41:49', '2026-11-04 20:41:49'),
(8, 8, 1, 2, 'medium', 'Monthly Bookkeeping', 'Client requested the listed service and the owner assigned a lead employee.', 'cancelled', '2026-11-04', '2026-11-20', 1, '2026-11-04 22:04:01', '2026-11-08 23:27:51'),
(9, 8, 15, 3, 'high', 'Payroll processing', 'Client requested the listed service and the owner assigned a lead employee.', 'completed', '2026-11-05', '2026-11-07', 1, '2026-11-05 00:09:08', '2026-11-08 23:25:50'),
(10, 4, 5, 6, 'high', 'SARS eFiling Submission', 'Client requested the listed service and the owner assigned a lead employee.', 'in_progress', '2026-11-05', '2026-11-09', 1, '2026-11-05 05:31:56', '2026-11-05 05:32:01'),
(11, 9, 2, 2, 'high', 'Bank Reconciliation', 'Client requested the listed service and the owner assigned a lead employee.', 'completed', '2026-11-05', '2026-11-06', 1, '2026-11-05 11:11:51', '2026-11-07 10:25:46'),
(12, 4, 4, 9, 'high', 'Business Income Tax Return', 'Client requested the listed service and the owner assigned a lead employee.', 'pending', '2026-11-08', '2026-11-09', 1, '2026-11-08 16:56:18', '2026-11-08 16:59:35');

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
  `task_id` int(10) UNSIGNED NOT NULL,
  `service_request_id` int(10) UNSIGNED NOT NULL,
  `task_title` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `start_date` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `progress_notes` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`task_id`, `service_request_id`, `task_title`, `description`, `assigned_to`, `created_by`, `start_date`, `due_date`, `priority`, `status`, `progress_notes`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'Prepare personal income tax return', 'Complete the assigned work for the related client request.', 2, 1, '2026-10-29', '2026-10-31', 'medium', 'completed', 'Progress updated by assigned employee.', '2026-11-02 22:42:53', '2026-10-29 10:05:14', '2026-11-02 22:42:53'),
(2, 1, 'Collect identification documents', 'Complete the assigned work for the related client request.', 2, 1, '2026-10-30', '2026-10-30', 'high', 'completed', 'Progress updated by assigned employee.', '2026-11-02 22:45:54', '2026-10-30 15:03:49', '2026-11-02 22:45:54'),
(3, 1, 'Review supporting documents', 'Complete the assigned work for the related client request.', 3, 1, '2026-11-02', '2026-11-02', 'low', 'completed', 'Progress updated by assigned employee.', '2026-11-02 22:34:41', '2026-11-02 11:06:05', '2026-11-02 22:34:41'),
(4, 1, 'Collect audit records', 'Complete the assigned work for the related client request.', 2, 1, '2026-11-02', '2026-11-04', 'medium', 'completed', 'Progress updated by assigned employee.', '2026-11-03 13:15:36', '2026-11-02 12:20:28', '2026-11-03 13:15:36'),
(5, 2, 'Collect taxation records', 'Complete the assigned work for the related client request.', 6, 1, '2026-11-03', '2026-11-06', 'high', 'completed', 'Progress updated by assigned employee.', '2026-11-03 13:34:43', '2026-11-03 13:29:15', '2026-11-03 13:34:43'),
(6, 6, 'Review bookkeeping entries', 'Complete the assigned work for the related client request.', 2, 1, '2026-11-04', '2026-11-06', 'medium', 'completed', 'Progress updated by assigned employee.', '2026-11-04 09:06:49', '2026-11-04 09:00:26', '2026-11-04 09:06:49'),
(7, 6, 'Compare transaction summaries', 'Complete the assigned work for the related client request.', 2, 1, '2026-11-06', '2026-11-13', 'medium', 'pending', NULL, NULL, '2026-11-04 09:02:02', '2026-11-04 09:02:02'),
(8, 2, 'Prepare monthly reconciliation', 'Complete the assigned work for the related client request.', 2, 1, '2026-10-27', '2026-11-01', 'urgent', 'in_progress', 'Progress updated by assigned employee.', NULL, '2026-11-04 18:26:50', '2026-11-04 18:26:50'),
(9, 8, 'Collect proof of address', 'Complete the assigned work for the related client request.', 2, 1, '2026-11-04', '2026-11-13', 'medium', 'completed', 'Progress updated by assigned employee.', '2026-11-08 23:27:03', '2026-11-04 22:05:52', '2026-11-08 23:27:03'),
(10, 9, 'Calculate earnings and deductions', 'Complete the assigned work for the related client request.', 3, 1, '2026-11-05', '2026-11-06', 'high', 'completed', 'Progress updated by assigned employee.', '2026-11-05 05:44:38', '2026-11-05 02:16:31', '2026-11-05 05:44:38'),
(11, 10, 'Prepare an eFiling submission', 'Complete the assigned work for the related client request.', 6, 1, '2026-11-05', '2026-11-06', 'medium', 'completed', 'Progress updated by assigned employee.', '2026-11-05 05:39:03', '2026-11-05 05:36:24', '2026-11-05 05:39:03'),
(12, 11, 'Collect audit summary', 'Complete the assigned work for the related client request.', 2, 1, '2026-11-05', '2026-11-06', 'medium', 'completed', 'Progress updated by assigned employee.', '2026-11-05 11:24:43', '2026-11-05 11:13:12', '2026-11-05 11:24:43'),
(13, 12, 'Verify client taxation documents', 'Complete the assigned work for the related client request.', 9, 1, '2026-11-08', '2026-11-09', 'high', 'completed', 'Progress updated by assigned employee.', '2026-11-08 17:42:40', '2026-11-08 16:58:31', '2026-11-08 17:42:40');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('owner','employee') NOT NULL,
  `account_status` enum('active','suspended','deactivated') NOT NULL DEFAULT 'active',
  `avatar_path` varchar(255) DEFAULT NULL,
  `failed_attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `privacy_accepted_at` datetime DEFAULT NULL,
  `privacy_policy_version` varchar(20) DEFAULT NULL,
  `session_version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `owner_slot` tinyint(4) GENERATED ALWAYS AS (case when `role` = 'owner' then 1 else NULL end) STORED,
  `phone_number` varchar(15) DEFAULT NULL,
  `alternative_phone_number` varchar(15) DEFAULT NULL,
  `employment_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `password_hash`, `role`, `account_status`, `avatar_path`, `failed_attempts`, `locked_until`, `last_login_at`, `privacy_accepted_at`, `privacy_policy_version`, `session_version`, `created_at`, `updated_at`, `phone_number`, `alternative_phone_number`, `employment_date`) VALUES
(1, 'K Moodley', 'kmoodley', 'k.moodley.nexsus@gmail.test', '$2y$12$QVmKF51LpJuqomG668G5sOYByQzjaj9vw7YK0ZR7Q6daMtbfWa7oG', 'owner', 'active', NULL, 0, NULL, '2026-11-09 14:45:23', '2026-11-09 14:45:27', '1.0', 1, '2026-10-26 03:46:40', '2026-11-09 14:45:27', NULL, NULL, NULL),
(2, 'Amira Pillay', 'amira.pillay', 'amira.pillay.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'active', NULL, 0, NULL, '2026-11-09 14:39:13', '2026-11-09 14:39:16', '1.0', 1, '2026-10-26 21:57:31', '2026-11-09 14:39:16', '0000001002', NULL, '2025-02-26'),
(3, 'Ravi Naidoo', 'ravi.naidoo', 'ravi.naidoo.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'active', NULL, 0, NULL, '2026-11-09 14:40:00', '2026-11-09 14:40:04', '1.0', 1, '2026-10-27 11:09:42', '2026-11-09 14:40:04', '0000001003', NULL, '2025-01-01'),
(4, 'Zinhle Dlamini', 'zinhle.dlamini', 'zinhle.dlamini.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'active', NULL, 0, NULL, NULL, NULL, NULL, 1, '2026-10-28 21:16:59', '2026-11-02 22:01:07', '0000001004', NULL, '2025-03-03'),
(5, 'Sanele Mkhize', 'sanele.mkhize', 'sanele.mkhize.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'deactivated', NULL, 0, NULL, NULL, NULL, NULL, 1, '2026-10-29 14:28:26', '2026-11-03 07:54:40', '0000001005', NULL, '2024-02-05'),
(6, 'Kiara Govender', 'kiara.govender', 'kiara.govender.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'active', NULL, 0, NULL, '2026-11-08 15:22:17', '2026-11-08 15:22:22', '1.0', 1, '2026-11-02 11:18:52', '2026-11-08 15:22:22', '0000001006', NULL, '2026-11-02'),
(7, 'Kabelo Zulu', 'kabelo.zulu', 'kabelo.zulu.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'deactivated', NULL, 0, NULL, NULL, NULL, NULL, 1, '2026-11-02 11:37:59', '2026-11-04 23:12:02', '0000001007', NULL, '2026-11-02'),
(8, 'Priya Reddy', 'priya.reddy', 'priya.reddy.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'active', NULL, 0, NULL, NULL, NULL, NULL, 1, '2026-11-05 10:55:48', '2026-11-05 10:55:48', '0000001008', NULL, '2026-11-03'),
(9, 'Sibusiso Maseko', 'sibusiso.maseko', 'sibusiso.maseko.nexsus@gmail.test', '$2y$12$yKkoY6hHEOxbhMk6pPDAZueW7WMfgie6A.yZYiNN/R4nDvgqdhvbu', 'employee', 'active', NULL, 0, NULL, '2026-11-08 17:35:07', '2026-11-08 17:35:14', '1.0', 1, '2026-11-08 16:00:44', '2026-11-08 17:35:14', '0000001009', NULL, '2026-10-01'),
(10, 'Rubasri Naidoo', 'r.naidoo', 'rubasrinaidoo22@gmail.com', '$2y$10$pfG2X/2cHUeV5LNuRfxaQ.XL2QkvvXLlcHY976Jjd8/hBo3pF5lCe', 'employee', 'active', NULL, 0, NULL, NULL, NULL, NULL, 1, '2026-11-09 14:48:23', '2026-11-09 14:48:23', '0812345679', NULL, '2026-10-05');

-- --------------------------------------------------------

--
-- Table structure for table `workflow_history`
--

DROP TABLE IF EXISTS `workflow_history`;
CREATE TABLE `workflow_history` (
  `workflow_history_id` bigint(20) UNSIGNED NOT NULL,
  `entity_type` enum('task','service_request') NOT NULL,
  `entity_id` int(10) UNSIGNED NOT NULL,
  `previous_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `progress_note` text DEFAULT NULL,
  `changed_by` int(10) UNSIGNED DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `workflow_history`
--

INSERT INTO `workflow_history` (`workflow_history_id`, `entity_type`, `entity_id`, `previous_status`, `new_status`, `progress_note`, `changed_by`, `changed_at`) VALUES
(1, 'service_request', 1, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-10-29 06:59:53'),
(2, 'task', 1, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-10-29 10:05:14'),
(3, 'task', 2, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-10-30 15:03:49'),
(4, 'task', 3, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-02 11:06:05'),
(5, 'task', 4, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-02 12:20:28'),
(6, 'service_request', 2, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-02 12:21:42'),
(7, 'task', 3, 'pending', 'completed', 'Fictional workflow status update for testing.', 3, '2026-11-02 22:34:42'),
(8, 'task', 1, 'pending', 'completed', 'Fictional workflow status update for testing.', 2, '2026-11-02 22:42:53'),
(9, 'task', 2, 'pending', 'completed', 'Fictional workflow status update for testing.', 2, '2026-11-02 22:45:54'),
(10, 'service_request', 3, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-02 22:50:08'),
(11, 'service_request', 4, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-03 08:53:03'),
(12, 'service_request', 5, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-03 09:46:38'),
(13, 'service_request', 5, 'pending', 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-03 09:47:49'),
(14, 'service_request', 4, 'pending', 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-03 09:48:08'),
(15, 'service_request', 4, 'pending', 'cancelled', 'Fictional workflow status update for testing.', 1, '2026-11-03 10:22:20'),
(16, 'task', 4, 'pending', 'completed', 'Fictional workflow status update for testing.', 2, '2026-11-03 13:15:36'),
(17, 'task', 5, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-03 13:29:15'),
(18, 'task', 5, 'pending', 'in_progress', 'Fictional workflow status update for testing.', 6, '2026-11-03 13:33:54'),
(19, 'task', 5, 'in_progress', 'completed', 'Fictional workflow status update for testing.', 6, '2026-11-03 13:34:43'),
(20, 'service_request', 3, 'pending', 'in_progress', 'Fictional workflow status update for testing.', 1, '2026-11-03 13:35:57'),
(21, 'service_request', 3, 'in_progress', 'in_progress', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:31:20'),
(22, 'service_request', 3, 'in_progress', 'completed', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:32:01'),
(23, 'service_request', 1, 'pending', 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:32:11'),
(24, 'service_request', 5, 'pending', 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:32:46'),
(25, 'service_request', 1, 'pending', 'cancelled', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:33:21'),
(26, 'service_request', 5, 'pending', 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:46:13'),
(27, 'service_request', 5, 'pending', 'cancelled', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:46:42'),
(28, 'service_request', 2, 'pending', 'cancelled', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:47:37'),
(29, 'service_request', 6, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 08:57:23'),
(30, 'task', 6, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 09:00:26'),
(31, 'task', 7, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 09:02:02'),
(32, 'task', 6, 'pending', 'completed', 'Fictional workflow status update for testing.', 2, '2026-11-04 09:06:49'),
(33, 'service_request', 7, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 20:41:49'),
(34, 'service_request', 8, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 22:04:01'),
(35, 'task', 9, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-04 22:05:52'),
(36, 'service_request', 9, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-05 00:09:08'),
(37, 'service_request', 9, 'pending', 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-05 01:59:01'),
(38, 'service_request', 9, 'pending', 'in_progress', 'Fictional workflow status update for testing.', 1, '2026-11-05 01:59:09'),
(39, 'task', 10, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-05 02:16:31'),
(40, 'service_request', 10, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-05 05:31:56'),
(41, 'service_request', 10, 'pending', 'in_progress', 'Fictional workflow status update for testing.', 1, '2026-11-05 05:32:01'),
(42, 'task', 11, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-05 05:36:24'),
(43, 'task', 11, 'pending', 'in_progress', 'Fictional workflow status update for testing.', 6, '2026-11-05 05:37:56'),
(44, 'task', 11, 'in_progress', 'completed', 'Fictional workflow status update for testing.', 6, '2026-11-05 05:39:03'),
(45, 'task', 10, 'pending', 'completed', 'Fictional workflow status update for testing.', 3, '2026-11-05 05:44:38'),
(46, 'service_request', 11, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-05 11:11:51'),
(47, 'task', 12, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-05 11:13:12'),
(48, 'task', 12, 'pending', 'completed', 'Fictional workflow status update for testing.', 2, '2026-11-05 11:24:43'),
(49, 'service_request', 11, 'pending', 'in_progress', 'Fictional workflow status update for testing.', 2, '2026-11-06 13:17:35'),
(50, 'service_request', 11, 'in_progress', 'in_progress', 'Fictional workflow status update for testing.', 2, '2026-11-06 13:17:42'),
(51, 'service_request', 11, 'in_progress', 'completed', 'Fictional workflow status update for testing.', 1, '2026-11-07 10:25:46'),
(52, 'service_request', 12, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-08 16:56:18'),
(53, 'task', 13, NULL, 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-08 16:58:31'),
(54, 'service_request', 12, 'pending', 'pending', 'Fictional workflow status update for testing.', 1, '2026-11-08 16:59:35'),
(55, 'task', 13, 'pending', 'in_progress', 'Fictional workflow status update for testing.', 9, '2026-11-08 17:36:58'),
(56, 'task', 13, 'in_progress', 'in_progress', 'Fictional workflow status update for testing.', 9, '2026-11-08 17:38:12'),
(57, 'task', 13, 'in_progress', 'completed', 'Fictional workflow status update for testing.', 9, '2026-11-08 17:42:40'),
(58, 'service_request', 9, 'in_progress', 'completed', 'Fictional workflow status update for testing.', 1, '2026-11-08 23:25:50'),
(59, 'task', 9, 'pending', 'completed', 'Fictional workflow status update for testing.', 2, '2026-11-08 23:27:03'),
(60, 'service_request', 8, 'pending', 'cancelled', 'Fictional workflow status update for testing.', 1, '2026-11-08 23:27:51');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_audit_date` (`created_at`),
  ADD KEY `idx_audit_actor` (`actor_user_id`,`created_at`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`client_id`);

--
-- Indexes for table `contact_requests`
--
ALTER TABLE `contact_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`document_id`),
  ADD UNIQUE KEY `uq_documents_stored_name` (`stored_name`),
  ADD UNIQUE KEY `uq_documents_previous` (`previous_document_id`),
  ADD KEY `idx_documents_client` (`client_id`,`document_status`,`created_at`),
  ADD KEY `idx_documents_request` (`service_request_id`),
  ADD KEY `idx_documents_task` (`task_id`),
  ADD KEY `idx_documents_category` (`category`,`document_status`),
  ADD KEY `idx_documents_uploader` (`uploaded_by`),
  ADD KEY `idx_documents_hash` (`file_hash`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notification_unread` (`user_id`,`read_at`,`created_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`reset_id`),
  ADD UNIQUE KEY `uq_reset_token` (`token_hash`),
  ADD KEY `idx_reset_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_reset_expiry` (`expires_at`);

--
-- Indexes for table `report_exports`
--
ALTER TABLE `report_exports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `idx_report_exports_generated` (`generated_at`,`generated_by`),
  ADD KEY `fk_report_exports_owner` (`generated_by`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`),
  ADD UNIQUE KEY `uq_service_name` (`service_name`),
  ADD UNIQUE KEY `uq_services_category_name` (`category`,`service_name`);

--
-- Indexes for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD PRIMARY KEY (`service_request_id`),
  ADD KEY `fk_request_creator` (`created_by`),
  ADD KEY `idx_requests_client` (`client_id`),
  ADD KEY `idx_requests_service` (`service_id`),
  ADD KEY `idx_requests_status_due` (`request_status`,`due_date`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `fk_task_creator` (`created_by`),
  ADD KEY `idx_task_assigned` (`assigned_to`,`status`,`due_date`),
  ADD KEY `idx_task_request` (`service_request_id`),
  ADD KEY `idx_task_due` (`due_date`,`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_only_one_owner` (`owner_slot`),
  ADD KEY `idx_users_role_status` (`role`,`account_status`);

--
-- Indexes for table `workflow_history`
--
ALTER TABLE `workflow_history`
  ADD PRIMARY KEY (`workflow_history_id`),
  ADD KEY `fk_workflow_user` (`changed_by`),
  ADD KEY `idx_workflow_record` (`entity_type`,`entity_id`,`changed_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `audit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `client_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `contact_requests`
--
ALTER TABLE `contact_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `document_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `reset_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_exports`
--
ALTER TABLE `report_exports`
  MODIFY `report_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `service_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `service_requests`
--
ALTER TABLE `service_requests`
  MODIFY `service_request_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `task_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `workflow_history`
--
ALTER TABLE `workflow_history`
  MODIFY `workflow_history_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`),
  ADD CONSTRAINT `fk_documents_previous` FOREIGN KEY (`previous_document_id`) REFERENCES `documents` (`document_id`),
  ADD CONSTRAINT `fk_documents_request` FOREIGN KEY (`service_request_id`) REFERENCES `service_requests` (`service_request_id`),
  ADD CONSTRAINT `fk_documents_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`task_id`),
  ADD CONSTRAINT `fk_documents_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `report_exports`
--
ALTER TABLE `report_exports`
  ADD CONSTRAINT `fk_report_exports_owner` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD CONSTRAINT `fk_request_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`),
  ADD CONSTRAINT `fk_request_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_request_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`);

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_task_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_task_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_task_request` FOREIGN KEY (`service_request_id`) REFERENCES `service_requests` (`service_request_id`);

--
-- Constraints for table `workflow_history`
--
ALTER TABLE `workflow_history`
  ADD CONSTRAINT `fk_workflow_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
SET FOREIGN_KEY_CHECKS=1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
