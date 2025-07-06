<?php

namespace Tests;

use AlinO\Db\MysqliDb;
use Symfony\Component\Dotenv\Dotenv;
use Tests\Models\User;

global $config;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected $backupGlobals = FALSE;
    protected static $defaultDb;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $p = getcwd();
        if (file_exists($p . '/.env.testing')) {
            $dotenv = new Dotenv();
            $dotenv->load($p . '/.env.testing');
        }
        $host = @$_ENV['DB_HOST'] ?: getenv('DB_HOST');
        $user = @$_ENV['DB_USER'] ?: getenv('DB_USER');
        $pass = @$_ENV['DB_PASS'] ?: getenv('DB_PASS');
        $dbname = @$_ENV['DB_DATABASE'] ?: getenv('DB_DATABASE');
        $port = @$_ENV['DB_PORT'] ?: getenv('DB_PORT');
        static::$defaultDb = $db = new MysqliDb($host, $user, $pass, $dbname, $port);
        User::setConnection($db);
        try {
            $db->connect();
        } catch (\Exception $e) {
            echo "Database connection error: " . $e->getMessage();
            exit;
        }
        $aes = @$_ENV['DB_AES'] ?: getenv('DB_AES');
        if (!empty($aes)) {
            $db->rawQuery("SET @aes_key = SHA2('$aes', 512)");
        }
        // Create countries table
            $db->rawQuery("CREATE TABLE IF NOT EXISTS `countries` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                PRIMARY KEY (`id`)
            )");

        try {
            $db->has('user_roles');
        } catch (\Exception $e) {
            // Create addresses table
            $db->rawQuery("CREATE TABLE IF NOT EXISTS `addresses` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `fname` varchar(100) DEFAULT NULL,
                `lname` varchar(100) DEFAULT NULL,
                `business` tinyint(1) NOT NULL DEFAULT 0,
                `company` varchar(250) DEFAULT NULL,
                `address` varchar(250) DEFAULT NULL,
                `address_nr` varchar(25) DEFAULT NULL,
                `address_add` varchar(250) DEFAULT NULL,
                `zipcode` varchar(10) DEFAULT NULL,
                `city` varchar(100) DEFAULT NULL,
                `county` varchar(100) DEFAULT NULL,                
                `vat_id` varchar(20) DEFAULT NULL,
                `country_id` int(11) DEFAULT NULL,
                `phone` varchar(100) DEFAULT NULL, 
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                KEY `country_id` (`country_id`)
            )");

            // Create users table
            $db->rawQuery("CREATE TABLE IF NOT EXISTS `users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(100) DEFAULT NULL,
                `aes_pwd` varbinary(160) DEFAULT NULL,
                `email` varchar(100) DEFAULT NULL,
                `phone` varchar(100) DEFAULT NULL,
                `fname` varchar(100) DEFAULT NULL,
                `lname` varchar(100) DEFAULT NULL,
                `join_tmst` int(11) DEFAULT NULL,
                `status` smallint(6) DEFAULT NULL,
                `activation_code` varchar(10) DEFAULT NULL,
                `reset_code` varchar(32) DEFAULT NULL,
                `social_provider` varchar(20) DEFAULT NULL,
                `social_id` varchar(255) DEFAULT NULL,
                `shipping_address` int(11) DEFAULT NULL,
                `billing_address` int(11) DEFAULT NULL,
                `country_id` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`,`email`),
                UNIQUE KEY `email` (`email`),
                KEY `shipping_address` (`shipping_address`),
                KEY `billing_address` (`billing_address`),
                KEY `country_id_fk` (`country_id`)
            )");

            // Create posts table
            $db->rawQuery("CREATE TABLE IF NOT EXISTS `posts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `title` varchar(255) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `user_id_fk` (`user_id`)
            )");

            // Create user_profiles table
            $db->rawQuery("CREATE TABLE IF NOT EXISTS `user_profiles` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `bio` TEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_id_unique` (`user_id`)
            )");

            // Create roles table
            $db->rawQuery("CREATE TABLE IF NOT EXISTS `roles` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            )");

            // Create user_roles join table
            $db->rawQuery("CREATE TABLE IF NOT EXISTS `user_roles` (
                `user_id` int(11) NOT NULL,
                `role_id` int(11) NOT NULL,
                PRIMARY KEY (`user_id`, `role_id`),
                KEY `role_id` (`role_id`)
            )");

            // Add constraints for addresses
            $db->rawQuery("ALTER TABLE `addresses` ADD CONSTRAINT `fk_addresses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;");
            $db->rawQuery("ALTER TABLE `addresses` ADD CONSTRAINT `fk_addresses_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE SET NULL;");

            // Add constraints for users
            $db->rawQuery("ALTER TABLE `users` ADD CONSTRAINT `fk_users_shipping_address` FOREIGN KEY (`shipping_address`) REFERENCES `addresses` (`id`) ON DELETE SET NULL;");
            $db->rawQuery("ALTER TABLE `users` ADD CONSTRAINT `fk_users_billing_address` FOREIGN KEY (`billing_address`) REFERENCES `addresses` (`id`) ON DELETE SET NULL;");
            $db->rawQuery("ALTER TABLE `users` ADD CONSTRAINT `fk_users_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE SET NULL;");

            // Add constraints for posts
            $db->rawQuery("ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;");

            // Add constraints for user_profiles
            $db->rawQuery("ALTER TABLE `user_profiles` ADD CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;");

            // Add constraints for user_roles
            $db->rawQuery("ALTER TABLE `user_roles` ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;");
            $db->rawQuery("ALTER TABLE `user_roles` ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;");

            // Seed a country if it doesn't exist
            $db->rawQuery("INSERT IGNORE INTO `countries` (`id`, `name`) VALUES (1, 'Default Country')");
            $db->rawQuery("INSERT IGNORE INTO `countries` (`id`, `name`) VALUES (2, 'Another Country')");

            // Seed a role if it doesn't exist
            $db->rawQuery("INSERT IGNORE INTO `roles` (`id`, `name`) VALUES (1, 'Default Role')");

            $db->rawQuery("INSERT IGNORE INTO users (id, username, email, fname, lname, join_tmst) VALUES
                (123, 'testuser1', 'existing@example.com', 'Test', 'User', UNIX_TIMESTAMP())");
            $db->rawQuery("INSERT IGNORE INTO users (id, username, email, fname, lname, join_tmst) VALUES
                (456, 'testuser2', 'another_existing@example.com', 'Test', 'User', UNIX_TIMESTAMP())");
        }
    }
}
