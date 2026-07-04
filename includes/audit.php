<?php

/**
 * -------------------------------------------------------------------------
 * Audit Logging Service
 * -------------------------------------------------------------------------
 */

if (!function_exists('getClientIP')) {
    function getClientIP()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

}


/**
 * -------------------------------------------------------------------------
 * Computer Name
 * -------------------------------------------------------------------------
 */

if (!function_exists('getComputerName')) {
    function getComputerName()
    {
        return gethostbyaddr(getClientIP());
    }
}


/**
 * -------------------------------------------------------------------------
 * Browser
 * -------------------------------------------------------------------------
 */

if (!function_exists('getUserAgent')) {
    function getUserAgent()
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}


/**
 * -------------------------------------------------------------------------
 * Username
 * -------------------------------------------------------------------------
 */

if (!function_exists('getAuditUsername')) {
    function getAuditUsername()
    {
        return $_SESSION['username']
            ?? $_SESSION['email']
            ?? "Unknown";
    }
}


/**
 * -------------------------------------------------------------------------
 * Main Logger
 * -------------------------------------------------------------------------
 */

if (!function_exists('logAudit')) {
    function logAudit(
        mysqli $conn,
        ?int $adminId,
        string $module,
        string $action,
        string $description
    ) {
        $username = getAuditUsername();
        $ip = getClientIP();
        $computer = getComputerName();
        $agent = getUserAgent();
        $stmt = $conn->prepare("
            INSERT INTO audit_logs
            (
                admin_id,
                username,
                module,
                action,
                description,
                ip_address,
                computer_name,
                user_agent
            )
            VALUES
            (?,?,?,?,?,?,?,?)

        ");

        $stmt->bind_param(
            "isssssss",
            $adminId,
            $username,
            $module,
            $action,
            $description,
            $ip,
            $computer,
            $agent
        );
        return $stmt->execute();
    }

}


/**
 * -------------------------------------------------------------------------
 * Get Audit By ID
 * -------------------------------------------------------------------------
 */

if (!function_exists('getAuditById')) {
    function getAuditById(mysqli $conn, int $id)
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM audit_logs
            WHERE id=?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();

    }

}


/**
 * -------------------------------------------------------------------------
 * Recent Logs
 * -------------------------------------------------------------------------
 */

if (!function_exists('getRecentAuditLogs')) {
    function getRecentAuditLogs(
        mysqli $conn,
        int $limit = 100
    ) {

        $limit = (int)$limit;
        return $conn->query("
            SELECT *
            FROM audit_logs
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
    }
}


/**
 * -------------------------------------------------------------------------
 * Delete Old Logs
 * -------------------------------------------------------------------------
 */

if (!function_exists('deleteOldAuditLogs')) {

    function deleteOldAuditLogs(
        mysqli $conn,
        int $days = 365
    ) {
        $stmt = $conn->prepare("
            DELETE
            FROM audit_logs
            WHERE created_at <
            DATE_SUB(NOW(),INTERVAL ? DAY)
        ");
        $stmt->bind_param("i", $days);
        return $stmt->execute();
    }
}


/**
 * -------------------------------------------------------------------------
 * Count Logs
 * -------------------------------------------------------------------------
 */

if (!function_exists('countAuditLogs')) {
    function countAuditLogs(mysqli $conn)
    {
        $result = $conn->query("
            SELECT COUNT(*) total
            FROM audit_logs
        ");
        return $result->fetch_assoc()['total'];
    }
}

/**
 * -------------------------------------------------------------------------
 * Count By Module
 * -------------------------------------------------------------------------
 */

if (!function_exists('countModuleLogs')) {
    function countModuleLogs(
        mysqli $conn,
        string $module
    ) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) total
            FROM audit_logs
            WHERE module=?
        ");
        $stmt->bind_param("s",$module);
        $stmt->execute();
        return $stmt->get_result()
                    ->fetch_assoc()['total'];
    }
}