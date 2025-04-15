<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x query execution methods
 */
final class QueryCompatibility
{
    /**
     * Execute a query with parameters, handling both DBAL 3.x and 4.x
     */
    public static function executeQuery(Connection $connection, string $sql, array $params = [], array $types = []): Result|Statement
    {
        try {
            // Convert parameter types for compatibility
            $convertedTypes = [];
            foreach ($types as $key => $type) {
                $convertedTypes[$key] = StatementCompatibility::convertBindingType($type);
            }

            // Execute the query
            return $connection->executeQuery($sql, $params, $convertedTypes);
        } catch (\Throwable $e) {
            // If there's an error, try a different approach
            try {
                // Try to prepare and execute the statement manually
                $stmt = $connection->prepare($sql);
                
                // Bind parameters
                foreach ($params as $key => $value) {
                    $type = $convertedTypes[$key] ?? (DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2);
                    
                    if (is_int($key)) {
                        // Positional parameter (1-based in PDO)
                        $stmt->bindValue($key + 1, $value, $type);
                    } else {
                        // Named parameter
                        $stmt->bindValue($key, $value, $type);
                    }
                }
                
                // Execute the statement
                $stmt->execute();
                return $stmt;
            } catch (\Throwable $e2) {
                // If both approaches fail, re-throw the original exception
                throw $e;
            }
        }
    }
    
    /**
     * Execute a statement with parameters, handling both DBAL 3.x and 4.x
     */
    public static function executeStatement(Connection $connection, string $sql, array $params = [], array $types = []): int
    {
        try {
            // Convert parameter types for compatibility
            $convertedTypes = [];
            foreach ($types as $key => $type) {
                $convertedTypes[$key] = StatementCompatibility::convertBindingType($type);
            }

            // Execute the statement
            return $connection->executeStatement($sql, $params, $convertedTypes);
        } catch (\Throwable $e) {
            // If there's an error, try a different approach
            try {
                // Try to prepare and execute the statement manually
                $stmt = $connection->prepare($sql);
                
                // Bind parameters
                foreach ($params as $key => $value) {
                    $type = $convertedTypes[$key] ?? (DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2);
                    
                    if (is_int($key)) {
                        // Positional parameter (1-based in PDO)
                        $stmt->bindValue($key + 1, $value, $type);
                    } else {
                        // Named parameter
                        $stmt->bindValue($key, $value, $type);
                    }
                }
                
                // Execute the statement and return the row count
                $stmt->execute();
                return $stmt->rowCount();
            } catch (\Throwable $e2) {
                // If both approaches fail, re-throw the original exception
                throw $e;
            }
        }
    }
    
    /**
     * Fetch all rows as associative arrays, handling both DBAL 3.x and 4.x
     */
    public static function fetchAllAssociative($result): array
    {
        try {
            // Try DBAL 3.x/4.x method
            if (method_exists($result, 'fetchAllAssociative')) {
                return $result->fetchAllAssociative();
            }
            
            // Try DBAL 2.x method
            if (method_exists($result, 'fetchAll')) {
                return $result->fetchAll(\PDO::FETCH_ASSOC);
            }
            
            // If neither method exists, throw an exception
            throw new \RuntimeException('Could not fetch all associative rows from result');
        } catch (\Throwable $e) {
            // If there's an error, re-throw it
            throw $e;
        }
    }
    
    /**
     * Fetch a single row as an associative array, handling both DBAL 3.x and 4.x
     */
    public static function fetchAssociative($result): array|false
    {
        try {
            // Try DBAL 3.x/4.x method
            if (method_exists($result, 'fetchAssociative')) {
                return $result->fetchAssociative();
            }
            
            // Try DBAL 2.x method
            if (method_exists($result, 'fetch')) {
                return $result->fetch(\PDO::FETCH_ASSOC);
            }
            
            // If neither method exists, throw an exception
            throw new \RuntimeException('Could not fetch associative row from result');
        } catch (\Throwable $e) {
            // If there's an error, re-throw it
            throw $e;
        }
    }
    
    /**
     * Fetch the first column of all rows, handling both DBAL 3.x and 4.x
     */
    public static function fetchFirstColumn($result): array
    {
        try {
            // Try DBAL 3.x/4.x method
            if (method_exists($result, 'fetchFirstColumn')) {
                return $result->fetchFirstColumn();
            }
            
            // Try DBAL 2.x method
            if (method_exists($result, 'fetchAll')) {
                return $result->fetchAll(\PDO::FETCH_COLUMN);
            }
            
            // If neither method exists, throw an exception
            throw new \RuntimeException('Could not fetch first column from result');
        } catch (\Throwable $e) {
            // If there's an error, re-throw it
            throw $e;
        }
    }
    
    /**
     * Fetch a single value from the first column of the first row, handling both DBAL 3.x and 4.x
     */
    public static function fetchOne($result): mixed
    {
        try {
            // Try DBAL 3.x/4.x method
            if (method_exists($result, 'fetchOne')) {
                return $result->fetchOne();
            }
            
            // Try DBAL 2.x method
            if (method_exists($result, 'fetchColumn')) {
                return $result->fetchColumn();
            }
            
            // If neither method exists, throw an exception
            throw new \RuntimeException('Could not fetch one value from result');
        } catch (\Throwable $e) {
            // If there's an error, re-throw it
            throw $e;
        }
    }
}
