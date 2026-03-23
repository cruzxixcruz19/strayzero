-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 22, 2026 at 07:03 AM
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
-- Database: `strayzero`
--

-- --------------------------------------------------------

--
-- Table structure for table `adoption_requests`
--

CREATE TABLE `adoption_requests` (
  `id` int(11) NOT NULL,
  `dog_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `adopter_name` varchar(100) DEFAULT NULL,
  `adopter_phone` varchar(20) DEFAULT NULL,
  `adopter_address` text DEFAULT NULL,
  `adopter_experience` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `claim_requests`
--

CREATE TABLE `claim_requests` (
  `id` int(11) NOT NULL,
  `dog_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `claimant_name` varchar(100) DEFAULT NULL,
  `claimant_phone` varchar(20) DEFAULT NULL,
  `claimant_address` text DEFAULT NULL,
  `proof_documents` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dogs`
--

CREATE TABLE `dogs` (
  `id` int(11) NOT NULL,
  `collar_id` varchar(20) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `size` enum('small','medium','large') DEFAULT NULL,
  `gender` enum('male','female','unknown') DEFAULT NULL,
  `age_estimate` int(11) DEFAULT NULL,
  `capture_date` date DEFAULT NULL,
  `capture_location` varchar(255) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `status` enum('captured','in_shelter','claimed','available','adopted') DEFAULT 'captured',
  `health_condition` text DEFAULT NULL,
  `vaccination_status` tinyint(1) DEFAULT 0,
  `days_in_shelter` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `type` enum('report','claim','adoption','admin') DEFAULT 'admin',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shelter_records`
--

CREATE TABLE `shelter_records` (
  `id` int(11) NOT NULL,
  `dog_id` int(11) DEFAULT NULL,
  `check_in_date` date DEFAULT NULL,
  `check_out_date` date DEFAULT NULL,
  `daily_food` varchar(100) DEFAULT NULL,
  `medical_notes` text DEFAULT NULL,
  `caretaker_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stray_reports`
--

CREATE TABLE `stray_reports` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `dog_color` varchar(50) DEFAULT NULL,
  `dog_size` enum('small','medium','large') DEFAULT NULL,
  `dog_behavior` text DEFAULT NULL,
  `location_lat` decimal(10,8) DEFAULT NULL,
  `location_lng` decimal(11,8) DEFAULT NULL,
  `location_address` varchar(255) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `status` enum('pending','captured','resolved') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `full_name`, `phone`, `address`, `profile_image`, `is_active`, `created_at`) VALUES
(1, 'admin', 'admin@strayzero.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'LGU Admin', NULL, NULL, NULL, 1, '2026-03-22 05:58:05');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `adoption_requests`
--
ALTER TABLE `adoption_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dog_id` (`dog_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `claim_requests`
--
ALTER TABLE `claim_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dog_id` (`dog_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `dogs`
--
ALTER TABLE `dogs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collar_id` (`collar_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `shelter_records`
--
ALTER TABLE `shelter_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dog_id` (`dog_id`);

--
-- Indexes for table `stray_reports`
--
ALTER TABLE `stray_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adoption_requests`
--
ALTER TABLE `adoption_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `claim_requests`
--
ALTER TABLE `claim_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dogs`
--
ALTER TABLE `dogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shelter_records`
--
ALTER TABLE `shelter_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stray_reports`
--
ALTER TABLE `stray_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `adoption_requests`
--
ALTER TABLE `adoption_requests`
  ADD CONSTRAINT `adoption_requests_ibfk_1` FOREIGN KEY (`dog_id`) REFERENCES `dogs` (`id`),
  ADD CONSTRAINT `adoption_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `claim_requests`
--
ALTER TABLE `claim_requests`
  ADD CONSTRAINT `claim_requests_ibfk_1` FOREIGN KEY (`dog_id`) REFERENCES `dogs` (`id`),
  ADD CONSTRAINT `claim_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `shelter_records`
--
ALTER TABLE `shelter_records`
  ADD CONSTRAINT `shelter_records_ibfk_1` FOREIGN KEY (`dog_id`) REFERENCES `dogs` (`id`);

--
-- Constraints for table `stray_reports`
--
ALTER TABLE `stray_reports`
  ADD CONSTRAINT `stray_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
