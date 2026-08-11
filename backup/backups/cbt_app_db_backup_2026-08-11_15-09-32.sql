-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: cbt_app_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `cbt_app_db`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `cbt_app_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `cbt_app_db`;

--
-- Table structure for table `academic_levels`
--

DROP TABLE IF EXISTS `academic_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `level_code` varchar(10) DEFAULT NULL,
  `class_group` enum('JSS','SS') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `level_code` (`level_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_levels`
--

LOCK TABLES `academic_levels` WRITE;
/*!40000 ALTER TABLE `academic_levels` DISABLE KEYS */;
INSERT INTO `academic_levels` VALUES (1,'JSS1','JSS'),(2,'SS1','SS'),(3,'JSS2','JSS');
/*!40000 ALTER TABLE `academic_levels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_years`
--

DROP TABLE IF EXISTS `academic_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` varchar(9) NOT NULL,
  `session` varchar(50) DEFAULT NULL,
  `exam_title` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'inactive',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_years`
--

LOCK TABLES `academic_years` WRITE;
/*!40000 ALTER TABLE `academic_years` DISABLE KEYS */;
INSERT INTO `academic_years` VALUES (1,'2025/2026',NULL,NULL,'inactive','2026-05-20 10:27:01'),(2,'2025/2026','Third Term','Test','active','2026-05-20 10:28:29');
/*!40000 ALTER TABLE `academic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `active_exams`
--

DROP TABLE IF EXISTS `active_exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `active_exams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT NULL,
  `exam_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `active_exams`
--

LOCK TABLES `active_exams` WRITE;
/*!40000 ALTER TABLE `active_exams` DISABLE KEYS */;
INSERT INTO `active_exams` VALUES (1,'mathematics',1,'2026-05-20 00:00:00'),(2,'mathematics',1,'2026-06-09 00:00:00'),(3,'mathematics',1,'2026-08-09 00:00:00');
/*!40000 ALTER TABLE `active_exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activities_log`
--

DROP TABLE IF EXISTS `activities_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activities_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity` text DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activities_log`
--

LOCK TABLES `activities_log` WRITE;
/*!40000 ALTER TABLE `activities_log` DISABLE KEYS */;
INSERT INTO `activities_log` VALUES (1,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:28:04'),(2,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:29:36'),(3,'Teacher created test: Third Term Test (academic_level_id1, mathematics (JSS))',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:29:58'),(4,'Teacher updated question ID 1: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:30:33'),(5,'Teacher updated question ID 2: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:31:04'),(6,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:31:45'),(7,'Admin admin accessed settings page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:31:48'),(8,'Admin admin accessed settings page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:31:54'),(9,'Admin admin updated daily subjects for 2026-05-20: mathematics',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:31:55'),(10,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-20 10:33:18'),(11,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:05:02'),(12,'Admin admin accessed settings page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:07:32'),(13,'Admin admin accessed settings page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:07:39'),(14,'Admin admin updated daily subjects for 2026-06-09: mathematics',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:07:41'),(15,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:09:10'),(16,'Teacher created test: Third Term Test (academic_level_id2, mathematics (SS))',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:09:28'),(17,'Teacher updated question ID 3: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:09:55'),(18,'Teacher updated question ID 4: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:10:19'),(19,'Teacher created test: Third Term Test (academic_level_id3, mathematics (JSS))',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:12:29'),(20,'Teacher updated question ID 5: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:12:45'),(21,'Teacher updated question ID 6: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:13:05'),(22,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-09 09:16:40'),(23,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-29 19:15:56'),(24,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-30 11:35:20'),(25,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-02 10:03:34'),(26,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-03 13:50:10'),(27,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-03 14:19:05'),(28,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-03 14:32:03'),(29,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 08:53:07'),(30,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 10:45:47'),(31,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 10:53:50'),(32,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 10:56:01'),(33,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 10:57:12'),(34,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 10:57:34'),(35,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 10:58:57'),(36,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 10:59:11'),(37,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:01:21'),(38,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:01:40'),(39,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:01:42'),(40,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:02:11'),(41,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:02:45'),(42,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:03:46'),(43,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:04:01'),(44,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:04:59'),(45,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:07:06'),(46,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:07:34'),(47,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:07:39'),(48,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:07:47'),(49,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:09:32'),(50,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:10:04'),(51,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:10:24'),(52,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:10:34'),(53,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:10:50'),(54,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:11:04'),(55,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:13:41'),(56,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:14:01'),(57,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:16:40'),(58,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:20:40'),(59,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:22:18'),(60,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:22:43'),(61,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:29:47'),(62,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:38:51'),(63,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:39:54'),(64,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:57:42'),(65,'Admin admin accessed view questions page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:57:47'),(66,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 11:58:01'),(67,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 12:03:38'),(68,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 12:43:05'),(69,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-04 12:43:17'),(70,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-05 15:16:35'),(71,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-05 15:17:22'),(72,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-05 15:18:50'),(73,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-07 12:01:47'),(74,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-07 12:17:11'),(75,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-07 12:18:54'),(76,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-07 12:19:21'),(77,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-07 13:13:14'),(78,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-07 13:13:24'),(79,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-07-07 13:13:37'),(80,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36','2026-07-11 12:59:46'),(81,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-03 14:14:24'),(82,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:23:31'),(83,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:23:41'),(84,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:23:53'),(85,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:25:36'),(86,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:33:56'),(87,'Admin admin accessed settings page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:34:01'),(88,'Admin admin accessed settings page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:34:09'),(89,'Admin admin updated daily subjects for 2026-08-09: mathematics',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:34:09'),(90,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:37:43'),(91,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:39:06'),(92,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 03:39:14'),(93,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 04:35:57'),(94,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 06:19:58'),(95,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 07:00:49'),(96,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 08:58:31'),(97,'Teacher saved question ID 7: nkkkjkjk',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 09:06:45'),(98,'Teacher saved question ID 8: nmnknknknknm',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 09:08:13'),(99,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 09:11:30'),(100,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 09:15:18'),(101,'Teacher saved question bank question ID 9: jhuhi',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 09:36:58'),(102,'Teacher saved question bank question ID 10: kgkdjgkg',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 09:41:06'),(103,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:19:33'),(104,'Teacher deleted question ID 4: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:19:47'),(105,'Teacher mr.john logged in',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:20:02'),(106,'Teacher saved question ID 12: from manual',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:27:00'),(107,'Teacher saved question bank question ID 13: from bank',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:27:48'),(108,'Teacher deleted question ID 7: nkkkjkjk',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:32:19'),(109,'Teacher deleted question ID 6: testing',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:44:29'),(110,'Teacher deleted question ID 8: nmnknknknknm',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:45:02'),(111,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:55:45'),(112,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 11:56:40'),(113,'Admin admin accessed view questions page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:34:30'),(114,'Admin admin accessed view questions page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:35:49'),(115,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:49:23'),(116,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:51:52'),(117,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:54:05'),(118,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:57:03'),(119,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:58:14'),(120,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 13:59:03'),(121,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 14:01:51'),(122,'Admin admin accessed backup details page.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 14:04:01'),(123,'Admin admin accessed the dashboard.',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-11 14:09:32');
/*!40000 ALTER TABLE `activities_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (1,'admin','$2y$10$hIZhQvnLfL7mdWkvXObUz.awBKLfiL5x47qaWTqh1C9k/dUoSL/eW',NULL,'admin');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `computer_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `module` (`module`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,'admin','LOGIN','Authentication','Successful login for username=admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-05 14:16:35'),(2,1,'superadmin','LOGIN','Authentication','Successful login for username=superadmin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-05 14:17:40'),(3,1,'admin','LOGIN','Authentication','Successful login for username=admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-05 14:18:50'),(4,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 10:26:01'),(5,1,'admin','LOGIN','Authentication','Successful login for username=admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 11:00:51'),(6,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 11:01:46'),(7,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 11:17:10'),(8,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 11:18:54'),(9,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 11:19:21'),(10,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 12:13:14'),(11,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 12:13:23'),(12,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-07 12:13:37'),(13,1,'superadmin','LOGIN','Authentication','Successful login for username=superadmin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-11 11:58:53'),(14,1,'admin','LOGIN','Authentication','Successful login for username=admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-11 11:59:42'),(15,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-07-11 11:59:45'),(16,1,'admin','LOGIN','Authentication','Successful login for username=admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-03 13:14:20'),(17,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-03 13:14:23'),(18,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:23:30'),(19,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:23:41'),(20,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:23:52'),(21,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:25:36'),(22,NULL,'Unknown','FAILED LOGIN','Authentication','Unknown username attempted: mr.peter','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:29:24'),(23,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:29:34'),(24,1,'admin','LOGIN','Authentication','Successful login for username=admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:33:56'),(25,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:33:56'),(26,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-09 02:37:43'),(27,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 01:14:16'),(28,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 01:19:07'),(29,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 03:00:15'),(30,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 03:35:56'),(31,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 03:58:12'),(32,1,'mr.john','LOGIN','Authentication','Successful login for username=mr.john','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 05:19:17'),(33,1,'admin','LOGIN','Authentication','Successful login for username=admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 10:55:43'),(34,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 10:55:45'),(35,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=1, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 10:56:39'),(36,1,'admin','Created database backup: cbt_app_db_backup_2026-08-11_13-54-34.sql','Backup','CREATE','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 11:54:37'),(37,1,'admin','Downloaded backup \'cbt_app_db_backup_2026-08-11_13-54-34.sql\'','Backups','DOWNLOAD','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 12:24:38'),(38,1,'admin','Downloaded backup \'cbt_app_db_backup_2026-08-11_13-54-34.sql\'','Backups','DOWNLOAD','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 12:59:11'),(39,1,'admin','Created database backup: cbt_app_db_backup_2026-08-11_15-03-45.sql','Backup','CREATE','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 13:03:50'),(40,1,'admin','CLEANUP','Backups','Automatic cleanup completed. Expired=0, Extra=0, Orphan Files=0, Orphan Records=0','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','kubernetes.docker.internal','2026-08-11 13:09:32');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backup_settings`
--

DROP TABLE IF EXISTS `backup_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auto_backup_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `backup_frequency` enum('hourly','daily','weekly','monthly') DEFAULT 'daily',
  `backup_time` time DEFAULT '00:00:00',
  `retention_days` int(11) DEFAULT 30,
  `max_backups` int(11) DEFAULT 50,
  `last_backup` datetime DEFAULT NULL,
  `next_backup` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backup_settings`
--

LOCK TABLES `backup_settings` WRITE;
/*!40000 ALTER TABLE `backup_settings` DISABLE KEYS */;
INSERT INTO `backup_settings` VALUES (1,1,'daily','00:00:00',30,50,NULL,NULL,'2026-07-06 14:33:04','2026-07-06 14:33:04');
/*!40000 ALTER TABLE `backup_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backups`
--

DROP TABLE IF EXISTS `backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `backup_type` enum('manual','automatic') DEFAULT 'manual',
  `status` enum('valid','corrupted') DEFAULT 'valid',
  `file_size` bigint(20) NOT NULL,
  `checksum` varchar(64) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `restore_count` int(11) DEFAULT 0,
  `restored_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `backups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backups`
--

LOCK TABLES `backups` WRITE;
/*!40000 ALTER TABLE `backups` DISABLE KEYS */;
INSERT INTO `backups` VALUES (1,'cbt_app_db_backup_2026-08-11_13-54-34.sql','manual','valid',73118,'c2fe815cd63cdadda45d944600a9dc49fec912760ad5ee220c6602d82f677ef0',1,'2026-08-11 11:54:37',0,NULL),(2,'cbt_app_db_backup_2026-08-11_15-03-45.sql','manual','valid',75972,'e87f14843a50e3767315b1e26e16f15db4697995b39e6b50e0b40c876a5001b1',1,'2026-08-11 13:03:50',0,NULL);
/*!40000 ALTER TABLE `backups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_level_id` int(11) NOT NULL,
  `stream_id` int(11) NOT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_level_id` (`academic_level_id`,`stream_id`),
  KEY `stream_id` (`stream_id`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`academic_level_id`) REFERENCES `academic_levels` (`id`),
  CONSTRAINT `classes_ibfk_2` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT INTO `classes` VALUES (1,1,1,'JSS1 Gold',1),(2,2,1,'SS1 Gold',1),(3,3,1,'JSS2 Gold',1);
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_attempts`
--

DROP TABLE IF EXISTS `exam_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer` text DEFAULT NULL,
  `is_flagged` tinyint(1) DEFAULT 0,
  `time_left` int(11) NOT NULL,
  `current_index` int(11) DEFAULT 0,
  `started_at` datetime DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attempt` (`user_id`,`test_id`,`question_id`),
  KEY `test_id` (`test_id`),
  CONSTRAINT `exam_attempts_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_attempts`
--

LOCK TABLES `exam_attempts` WRITE;
/*!40000 ALTER TABLE `exam_attempts` DISABLE KEYS */;
INSERT INTO `exam_attempts` VALUES (19,7,1,0,NULL,0,600,0,'2026-08-09 04:35:29','2026-08-09 02:35:29','2026-08-09 02:35:29');
/*!40000 ALTER TABLE `exam_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fill_blank_questions`
--

DROP TABLE IF EXISTS `fill_blank_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fill_blank_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) DEFAULT NULL,
  `correct_answer` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `fill_blank_questions_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `new_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fill_blank_questions`
--

LOCK TABLES `fill_blank_questions` WRITE;
/*!40000 ALTER TABLE `fill_blank_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `fill_blank_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grade_scales`
--

DROP TABLE IF EXISTS `grade_scales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grade_scales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `min_score` tinyint(3) unsigned NOT NULL,
  `max_score` tinyint(3) unsigned NOT NULL,
  `grade` char(1) NOT NULL,
  `remark` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grade` (`grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grade_scales`
--

LOCK TABLES `grade_scales` WRITE;
/*!40000 ALTER TABLE `grade_scales` DISABLE KEYS */;
/*!40000 ALTER TABLE `grade_scales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `image_questions`
--

DROP TABLE IF EXISTS `image_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `image_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) DEFAULT NULL,
  `image_path` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `correct_answer` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `image_questions_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `new_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `image_questions`
--

LOCK TABLES `image_questions` WRITE;
/*!40000 ALTER TABLE `image_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `image_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `licenses`
--

DROP TABLE IF EXISTS `licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `licenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `license_key` varchar(255) NOT NULL,
  `school_name` varchar(255) DEFAULT NULL,
  `machine_fingerprint` varchar(255) DEFAULT NULL,
  `installation_id` varchar(255) DEFAULT NULL,
  `installation_signature` varchar(255) DEFAULT NULL,
  `license_signature` varchar(1024) DEFAULT NULL,
  `license_type` varchar(100) DEFAULT NULL,
  `activation_date` datetime DEFAULT NULL,
  `expiry_date` datetime DEFAULT NULL,
  `status` enum('inactive','active','expired','revoked') DEFAULT 'inactive',
  `last_verified` datetime DEFAULT NULL,
  `last_system_time` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `license_key` (`license_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `licenses`
--

LOCK TABLES `licenses` WRITE;
/*!40000 ALTER TABLE `licenses` DISABLE KEYS */;
INSERT INTO `licenses` VALUES (1,'{\"license_id\":\"a5bbbfcd-8f8a-4439-8617-d22a2ff20d54\",\"school_id\":\"9ba3a21f-fe5c-45ea-80c6-bf7e73b8f4c8\",\"school\":\"CBT School 3EA1CE5F\",\"school_code\":null,\"product_code\":\"cbt_exam\",\"product_name\":\"CBT Examination Software\",\"machine\":\"3EA1CE5FE67FE66080711C','CBT School 3EA1CE5F','3EA1CE5FE67FE66080711C53E9DDF676707A42F7C31F23102F3D928F98B3080B','D418DD1D2C6AB02313AC80C1E9B8306A7587CA05917CF44DA71A3A91C7A2F53E','d313f163d93dd7cf33acf8e408e0f0720adedecba3fb6f6993205ea389fec73f','bG3JuH/Q8kpIFL3TYDEwMpj1kTOSEgCCXMmoNe5Ccq35b9O218MBTLqwrQRVLUFEHeybWvDBlql1x0vo+YwxDBSNnlX9D1xDLW4aaOLwAJx46hAqZharMoIbP7d2MBE4E0pmUJRXdn7H9hKMkfn9I2t/XEsP103FWh5I1fL1eC8iDZrD4wImDWRRp3eG4CJtBs+Ao+BA58pDE4otg3+NnfB5n53zA/QiecntjUWJ0EWGGuCrbF8toPOa1kdeIlPUHzVDYH5XkebF5bXx7+l7beOVosbtXFrgtKIyGejIDKRqBCbZdgXHfb1Qz6+xT7hws7WsrUMay1gvn6+CKFvhjR1u34f6+SnjJo3LZGfPpjkMsz3C5DPJLdTCn5bwh2riWGSlB/ZTOWwd+GO3JK7eGOqnLO4hJZGMpkzNCeWv3in9R2+1uNjssQ0f8tMWGywUwpqDBqTzdgskYIkgY2mFH5iStgVuhZH4q2Rr6GFiFjiHDhP/FD/YrDQDRIlJwAXGECQ0xXV/Ofg2iTYqcFjqt4c0m37KkWJlWSovCmlPtX12OKOpXwRrIrmrcRjnVKvD2SsbxravyrqPRvlzaH4bol6n7HARybhyhZohjOmmg+0NEnU7h8xVx2y4iegz5DRAFijP7PiWJ6v6uFGRtAg2YsWKkWoehM+SP8WhefGPOZs=','12_months','2026-08-11 07:19:57','2027-08-11 05:19:54','active','2026-08-11 12:45:18','2026-08-11 12:45:18','2026-08-11 06:19:57');
/*!40000 ALTER TABLE `licenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `multiple_choice_questions`
--

DROP TABLE IF EXISTS `multiple_choice_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `multiple_choice_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) DEFAULT NULL,
  `option1` text DEFAULT NULL,
  `option2` text DEFAULT NULL,
  `option3` text DEFAULT NULL,
  `option4` text DEFAULT NULL,
  `correct_answers` text DEFAULT NULL,
  `image_path` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `multiple_choice_questions_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `new_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `multiple_choice_questions`
--

LOCK TABLES `multiple_choice_questions` WRITE;
/*!40000 ALTER TABLE `multiple_choice_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `multiple_choice_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `new_questions`
--

DROP TABLE IF EXISTS `new_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `new_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_text` text DEFAULT NULL,
  `test_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `class` varchar(20) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `question_type` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `test_id` (`test_id`),
  CONSTRAINT `new_questions_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `new_questions`
--

LOCK TABLES `new_questions` WRITE;
/*!40000 ALTER TABLE `new_questions` DISABLE KEYS */;
INSERT INTO `new_questions` VALUES (1,'testing',1,NULL,'JSS','mathematics (JSS)','multiple_choice_single','2026-05-20 10:30:33'),(2,'testing',1,NULL,'JSS','mathematics (JSS)','multiple_choice_single','2026-05-20 10:31:04'),(3,'testing',2,NULL,'SS','mathematics (SS)','multiple_choice_single','2026-06-09 09:09:54'),(5,'testing',3,NULL,'JSS','mathematics (JSS)','multiple_choice_single','2026-06-09 09:12:45'),(9,'jhuhi',NULL,1,'JSS1','mathematics (JSS)','multiple_choice_single','2026-08-11 09:36:57'),(10,'kgkdjgkg',NULL,1,'JSS1','mathematics (JSS)','multiple_choice_single','2026-08-11 09:41:06'),(11,'jhuhi',3,NULL,'3','mathematics (JSS)','multiple_choice_single','2026-08-11 10:47:34'),(12,'from manual',2,NULL,'SS','mathematics (SS)','multiple_choice_single','2026-08-11 11:26:59'),(13,'from bank',NULL,1,'SS1','mathematics (SS)','multiple_choice_single','2026-08-11 11:27:47'),(14,'from bank',2,NULL,'2','mathematics (SS)','multiple_choice_single','2026-08-11 11:27:55');
/*!40000 ALTER TABLE `new_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reattempt_schedule`
--

DROP TABLE IF EXISTS `reattempt_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reattempt_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `test_id` int(11) DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reattempt_schedule`
--

LOCK TABLES `reattempt_schedule` WRITE;
/*!40000 ALTER TABLE `reattempt_schedule` DISABLE KEYS */;
/*!40000 ALTER TABLE `reattempt_schedule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `results`
--

DROP TABLE IF EXISTS `results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `test_id` int(11) DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  `total_questions` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `reattempt_approved` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `test_id` (`test_id`),
  CONSTRAINT `results_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `results`
--

LOCK TABLES `results` WRITE;
/*!40000 ALTER TABLE `results` DISABLE KEYS */;
INSERT INTO `results` VALUES (1,1,1,2,2,'2026-05-20 10:34:03',NULL,NULL),(2,2,2,2,2,'2026-06-09 09:10:59',NULL,NULL),(3,3,2,2,2,'2026-06-09 09:11:50',NULL,NULL),(4,4,1,2,2,'2026-06-09 09:12:11',NULL,NULL),(5,5,3,2,2,'2026-06-09 09:13:25',NULL,NULL),(6,6,3,2,2,'2026-06-09 09:13:44',NULL,NULL);
/*!40000 ALTER TABLE `results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schools`
--

DROP TABLE IF EXISTS `schools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schools` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_name` (`school_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schools`
--

LOCK TABLES `schools` WRITE;
/*!40000 ALTER TABLE `schools` DISABLE KEYS */;
INSERT INTO `schools` VALUES (1,'tofek college','2026-05-20 09:26:55');
/*!40000 ALTER TABLE `schools` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_name` varchar(100) DEFAULT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `single_choice_questions`
--

DROP TABLE IF EXISTS `single_choice_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `single_choice_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) DEFAULT NULL,
  `option1` text DEFAULT NULL,
  `option2` text DEFAULT NULL,
  `option3` text DEFAULT NULL,
  `option4` text DEFAULT NULL,
  `correct_answer` text DEFAULT NULL,
  `image_path` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `single_choice_questions_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `new_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `single_choice_questions`
--

LOCK TABLES `single_choice_questions` WRITE;
/*!40000 ALTER TABLE `single_choice_questions` DISABLE KEYS */;
INSERT INTO `single_choice_questions` VALUES (1,1,'testing1','testing2','testing3','testing4','testing1',NULL),(2,2,'testing1','testing2','testing3','testing4','testing1',NULL),(3,3,'testing1','testing2','testing3','testing4','testing1',NULL),(5,5,'testing1','testing2','testing3','testing4','testing1',NULL),(9,9,'dfeff','eefe','fefef','effefe','dfeff',NULL),(10,10,'v m e, e,','evvef','eefeff','eefef','v m e, e,',NULL),(11,11,'dfeff','eefe','fefef','effefe','dfeff',NULL),(12,12,'bnfknkfnb','m f rjjngnrgnr','bjrnjnkrn','bnrkngrng','bnfknkfnb',NULL),(13,13,'m vmd kv','dv nmd vmd','dv nmd v','dv m kdvv','m vmd kv',NULL),(14,14,'m vmd kv','dv nmd vmd','dv nmd v','dv m kdvv','m vmd kv',NULL);
/*!40000 ALTER TABLE `single_choice_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `streams`
--

DROP TABLE IF EXISTS `streams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `streams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stream_name` (`stream_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `streams`
--

LOCK TABLES `streams` WRITE;
/*!40000 ALTER TABLE `streams` DISABLE KEYS */;
INSERT INTO `streams` VALUES (1,'Gold');
/*!40000 ALTER TABLE `streams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_subject_scores`
--

DROP TABLE IF EXISTS `student_subject_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_subject_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `ca1` tinyint(3) unsigned DEFAULT 0,
  `ca2` tinyint(3) unsigned DEFAULT 0,
  `ca3` tinyint(3) unsigned DEFAULT 0,
  `ca4` tinyint(3) unsigned DEFAULT 0,
  `exam` tinyint(3) unsigned DEFAULT 0,
  `total` tinyint(3) unsigned DEFAULT 0,
  `grade` char(1) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`,`subject_id`,`academic_year_id`),
  KEY `class_id` (`class_id`),
  KEY `subject_id` (`subject_id`),
  KEY `academic_year_id` (`academic_year_id`),
  CONSTRAINT `student_subject_scores_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_subject_scores_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`),
  CONSTRAINT `student_subject_scores_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`),
  CONSTRAINT `student_subject_scores_ibfk_4` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_subject_scores`
--

LOCK TABLES `student_subject_scores` WRITE;
/*!40000 ALTER TABLE `student_subject_scores` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_subject_scores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_term_remarks`
--

DROP TABLE IF EXISTS `student_term_remarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_term_remarks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `remark` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`,`academic_year_id`),
  KEY `academic_year_id` (`academic_year_id`),
  KEY `teacher_id` (`teacher_id`),
  CONSTRAINT `student_term_remarks_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_term_remarks_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`),
  CONSTRAINT `student_term_remarks_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_term_remarks`
--

LOCK TABLES `student_term_remarks` WRITE;
/*!40000 ALTER TABLE `student_term_remarks` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_term_remarks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `reg_no` varchar(50) DEFAULT NULL,
  `class` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'student',
  `created_via` enum('exam','class_management') DEFAULT 'exam',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reg_no` (`reg_no`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (1,'kunle','','1','','',NULL,'','student','class_management','2026-05-20 09:33:32','2026-05-20 09:33:32'),(2,'chris',NULL,'2',NULL,NULL,NULL,NULL,'student','exam','2026-06-09 08:10:49','2026-06-09 08:10:49'),(3,'john',NULL,'2',NULL,NULL,NULL,NULL,'student','exam','2026-06-09 08:11:41','2026-06-09 08:11:41'),(4,'chris',NULL,'1',NULL,NULL,NULL,NULL,'student','exam','2026-06-09 08:12:01','2026-06-09 08:12:01'),(5,'john',NULL,'3',NULL,NULL,NULL,NULL,'student','exam','2026-06-09 08:13:17','2026-06-09 08:13:17'),(6,'chris',NULL,'3',NULL,NULL,NULL,NULL,'student','exam','2026-06-09 08:13:36','2026-06-09 08:13:36'),(7,'tosin makinde',NULL,'1',NULL,NULL,NULL,NULL,'student','exam','2026-08-09 02:35:29','2026-08-09 02:35:29');
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_levels`
--

DROP TABLE IF EXISTS `subject_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `class_level` enum('PRIMARY','JSS','SS') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_id` (`subject_id`,`class_level`),
  CONSTRAINT `subject_levels_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_levels`
--

LOCK TABLES `subject_levels` WRITE;
/*!40000 ALTER TABLE `subject_levels` DISABLE KEYS */;
INSERT INTO `subject_levels` VALUES (1,1,'JSS'),(2,1,'SS');
/*!40000 ALTER TABLE `subject_levels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_name` (`subject_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES (1,'mathematics');
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `super_admins`
--

DROP TABLE IF EXISTS `super_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `super_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `super_admins`
--

LOCK TABLES `super_admins` WRITE;
/*!40000 ALTER TABLE `super_admins` DISABLE KEYS */;
INSERT INTO `super_admins` VALUES (1,'superadmin','$2y$10$QrC7gYk43ymbXhubbPktkeOegukBei/SPqAnkPmiHqDyrH7NqwpbO',NULL,'super_admin');
/*!40000 ALTER TABLE `super_admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setup_completed` tinyint(1) DEFAULT 0,
  `setup_completed_at` timestamp NULL DEFAULT NULL,
  `setup_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,1,'2026-05-20 09:27:37',1,'2026-05-20 09:27:37');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_classes`
--

DROP TABLE IF EXISTS `teacher_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_classes` (
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  PRIMARY KEY (`teacher_id`,`class_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `teacher_classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`),
  CONSTRAINT `teacher_classes_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_classes`
--

LOCK TABLES `teacher_classes` WRITE;
/*!40000 ALTER TABLE `teacher_classes` DISABLE KEYS */;
/*!40000 ALTER TABLE `teacher_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_subjects`
--

DROP TABLE IF EXISTS `teacher_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  CONSTRAINT `teacher_subjects_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_subjects`
--

LOCK TABLES `teacher_subjects` WRITE;
/*!40000 ALTER TABLE `teacher_subjects` DISABLE KEYS */;
INSERT INTO `teacher_subjects` VALUES (3,1,'mathematics (JSS)'),(4,1,'mathematics (SS)');
/*!40000 ALTER TABLE `teacher_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teachers`
--

DROP TABLE IF EXISTS `teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teachers`
--

LOCK TABLES `teachers` WRITE;
/*!40000 ALTER TABLE `teachers` DISABLE KEYS */;
INSERT INTO `teachers` VALUES (1,'mr','john','mr.john','john@example.com','$2y$10$HT2SXH1Flaciwa2B8hJJ4.D9HPaNn6rQUhdS29c/j6bgsRuZJdaHe','08109832619',NULL,'teacher');
/*!40000 ALTER TABLE `teachers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tests`
--

DROP TABLE IF EXISTS `tests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) DEFAULT NULL,
  `academic_level_id` int(11) NOT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `year` varchar(9) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `academic_level_id` (`academic_level_id`),
  CONSTRAINT `tests_ibfk_1` FOREIGN KEY (`academic_level_id`) REFERENCES `academic_levels` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tests`
--

LOCK TABLES `tests` WRITE;
/*!40000 ALTER TABLE `tests` DISABLE KEYS */;
INSERT INTO `tests` VALUES (1,'Third Term Test',1,'mathematics (JSS)','2025/2026',10,'2026-05-20 10:29:58'),(2,'Third Term Test',2,'mathematics (SS)','2025/2026',10,'2026-06-09 09:09:28'),(3,'Third Term Test',3,'mathematics (JSS)','2025/2026',10,'2026-06-09 09:12:29');
/*!40000 ALTER TABLE `tests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `true_false_questions`
--

DROP TABLE IF EXISTS `true_false_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `true_false_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) DEFAULT NULL,
  `correct_answer` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `true_false_questions_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `new_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `true_false_questions`
--

LOCK TABLES `true_false_questions` WRITE;
/*!40000 ALTER TABLE `true_false_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `true_false_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'cbt_app_db'
--

--
-- Dumping routines for database 'cbt_app_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-11 14:09:36
