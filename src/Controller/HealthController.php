<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health')]
    public function index(Connection $connection): JsonResponse
    {
        try {
            // Това е магията: пращаме реално запитване до Aiven, за да го държим буден!
            $connection->executeQuery('SELECT 1');

            return new JsonResponse([
                'status' => 'ok',
                'database' => 'awake and running!'
            ], 200);

        } catch (\Exception $e) {
            // Ако базата случайно спи, връщаме грешка, за да светне UptimeRobot в червено
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Database is sleeping or unreachable'
            ], 500);
        }
    }
}
