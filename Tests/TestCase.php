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
                `country_id` smallint(6) UNSIGNED DEFAULT NULL,
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
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`,`email`),
                UNIQUE KEY `email` (`email`),
                KEY `shipping_address` (`shipping_address`),
                KEY `billing_address` (`billing_address`)
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
            $db->rawQuery("ALTER TABLE `addresses`
                ADD CONSTRAINT `addresses_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
                ADD CONSTRAINT `addresses_ibfk_2` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`)
            )");

            // Add constraints for users
            $db->rawQuery("ALTER TABLE `users`
                ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`shipping_address`) REFERENCES `addresses` (`id`),
                ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`billing_address`) REFERENCES `addresses` (`id`)
            )");

            // Add constraints for user_roles
            $db->rawQuery("ALTER TABLE `user_roles`
                ADD CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
                ADD CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
            )");
        }
    }
}
