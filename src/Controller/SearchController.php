<?php

namespace App\Controller;

use App\Entity\Item;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SearchController extends AbstractController
{
    #[Route('/search', name: 'app_search')]
    public function index(Request $request, ItemRepository $itemRepository): Response
    {
        $query = $request->query->get('q', '');

        if ($query) {
            $items = $itemRepository->createQueryBuilder('i')
                ->where('LOWER(i.name) LIKE :query OR LOWER(i.code) LIKE :query')
                ->setParameter('query', '%' . mb_strtolower($query, 'UTF-8') . '%')
                ->getQuery()
                ->getResult();
        } else {
            $items = $itemRepository->findAll();
        }

        if ($request->headers->get('Accept') === 'application/json') {
            return $this->json($items);
        }

        return $this->render('search/index.html.twig', [
            'items' => $items,
            'query' => $query,
        ]);
    }

    #[Route('/api/update-item-inline', name: 'api_update_item_inline', methods: ['POST'])]
    public function updateItemInline(Request $request, ItemRepository $itemRepository, EntityManagerInterface $entityManager): Response
    {
        $data = json_decode($request->getContent(), true);
        $id = $data['id'] ?? null;
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;

        if (!$id || !$field) {
            return $this->json(['success' => false, 'error' => 'Невалидни данни!'], 400);
        }

        $item = $itemRepository->find($id);
        if (!$item) {
            return $this->json(['success' => false, 'error' => 'Артикулът не е намерен!'], 404);
        }

        if ($field === 'quantity') {
            $newQty = (float)$value;
            if ($newQty <= 0) {
                $entityManager->remove($item);
            } else {
                $item->setQuantity($newQty);
            }
        } elseif ($field === 'price') {
            $item->setPrice((float)$value);
        } elseif ($field === 'unit') {
            $item->setUnit(trim($value));
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/api/analyze-image', name: 'api_analyze_image', methods: ['POST'])]
    public function analyzeImage(Request $request, ItemRepository $itemRepository, \App\Service\GeminiService $geminiService): Response
    {
        $filePath = null;

        $uploadedFile = $request->files->get('image');
        if ($uploadedFile) {
            $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads';
            $newFilename = uniqid('img_', true) . '.' . $uploadedFile->guessExtension();
            $uploadedFile->move($uploadsDir, $newFilename);
            $filePath = $uploadsDir . '/' . $newFilename;
        } else {
            $data = json_decode($request->getContent(), true);
            $filename = $data['filename'] ?? $request->request->get('filename');

            if ($filename) {
                $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $filename;
            }
        }

        if (!$filePath || !file_exists($filePath)) {
            return $this->json(['error' => 'Няма намерен файл за обработка!'], 400);
        }

        try {
            $base64Image = base64_encode(file_get_contents($filePath));
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = ($ext === 'png') ? 'image/png' : 'image/jpeg';

            // Изискваме от AI да върне задължително "unit" (мерна единица)
            $prompt = 'Ти си експерт по инвентаризация и разчитане на документи за военни материални средства. ' .
                'На тази снимка има протокол с множество редове (материали). ' .
                'Обходи ВСИЧКИ редове. Върни САМО валиден JSON масив, БЕЗ каквито и да е обяснения, БЕЗ markdown блокове, точно в този формат: ' .
                '[{"code": "3026КЕИ0047К1", "name": "Кабел силов", "unit": "м.", "quantity": 30}]';

            $rawResult = $geminiService->analyzeImage($prompt, $base64Image, $mimeType);

            // Бронирано извличане на JSON
            $start = strpos($rawResult, '[');
            $end = strrpos($rawResult, ']');

            if ($start !== false && $end !== false && $end > $start) {
                $cleanJson = substr($rawResult, $start, $end - $start + 1);
            } else {
                $cleanJson = str_replace(['```json', '```'], '', $rawResult);
            }

            $aiData = json_decode(trim($cleanJson), true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($aiData)) {
                return $this->json([
                    'error' => 'AI не успя да разчете списъка правилно.',
                    'json_error' => json_last_error_msg(),
                    'raw_response' => $rawResult,
                    'attempted_json' => $cleanJson
                ], 400);
            }

            $checkedItems = [];

            foreach ($aiData as $scannedItem) {
                $code = $scannedItem['code'] ?? null;
                if (!$code) continue;

                $dbItem = $itemRepository->findOneBy(['code' => $code]);

                $checkedItems[] = [
                    'code' => $code,
                    'scanned_name' => $scannedItem['name'] ?? 'Няма име',
                    'scanned_quantity' => $scannedItem['quantity'] ?? 0,
                    'scanned_unit' => $scannedItem['unit'] ?? 'бр.', // Извличаме мерната единица
                    'exists_in_db' => $dbItem !== null,
                    'db_id' => $dbItem ? $dbItem->getId() : null,
                    'db_name' => $dbItem ? $dbItem->getName() : null,
                    'current_stock' => $dbItem ? $dbItem->getQuantity() : 0,
                ];
            }

            return $this->json([
                'success' => true,
                'items' => $checkedItems
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Грешка при обработката: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/update-inventory-batch', name: 'api_update_inventory_batch', methods: ['POST'])]
    public function updateInventoryBatch(Request $request, ItemRepository $itemRepository, EntityManagerInterface $entityManager): Response
    {
        $data = json_decode($request->getContent(), true);
        $action = $data['action'] ?? null;
        $items = $data['items'] ?? [];

        if (!$action || empty($items)) {
            return $this->json(['error' => 'Невалидни данни за обработка!'], 400);
        }

        $processedLog = [];

        foreach ($items as $scannedItem) {
            $code = $scannedItem['code'] ?? null;
            $scannedName = $scannedItem['scanned_name'] ?? 'Нов артикул';
            $scannedQty = floatval($scannedItem['scanned_quantity'] ?? 0);
            $scannedUnit = $scannedItem['scanned_unit'] ?? 'бр.';

            // НОВО: Четем цената, която Android приложението ни изпраща!
            $scannedPrice = floatval($scannedItem['scanned_price'] ?? 0.00);

            if (!$code || $scannedQty <= 0) continue;

            $dbItem = $itemRepository->findOneBy(['code' => $code]);

            if (!$dbItem) {
                if ($action === 'add') {
                    $dbItem = new Item();
                    $dbItem->setCode($code);
                    $dbItem->setName($scannedName);
                    $dbItem->setQuantity((string)$scannedQty);
                    $dbItem->setUnit($scannedUnit);

                    // НОВО: Записваме истинската цена, а не 0.00
                    $dbItem->setPrice($scannedPrice);

                    $entityManager->persist($dbItem);

                    $processedLog[] = "Създаден нов: {$scannedName} ({$code}) с количество {$scannedQty} {$scannedUnit} на цена {$scannedPrice}";
                } else {
                    $processedLog[] = "Пропуснат (липсва в БД за изписване): {$code}";
                }
                continue;
            }

            $currentStock = (float) $dbItem->getQuantity();

            if ($action === 'remove') {
                $newStock = $currentStock - $scannedQty;

                if ($newStock <= 0) {
                    $entityManager->remove($dbItem);
                    $processedLog[] = "Изписан и изтрит (0): {$dbItem->getName()} ({$code})";
                } else {
                    $dbItem->setQuantity((string)$newStock);
                    $processedLog[] = "Изписано: {$scannedQty} от {$dbItem->getName()} (Остават: {$newStock})";
                }
            } elseif ($action === 'add') {
                $newStock = $currentStock + $scannedQty;
                $dbItem->setQuantity((string)$newStock);

                // НОВО: Ако добавяме наличност и сме въвели нова цена от телефона, я обновяваме и нея
                if ($scannedPrice > 0) {
                    $dbItem->setPrice($scannedPrice);
                }

                $processedLog[] = "Заприходено: +{$scannedQty} към {$dbItem->getName()} (Общо: {$newStock})";
            }
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => ($action === 'add' ? 'Успешно заприхождаване!' : 'Успешно изписване!'),
            'log' => $processedLog
        ]);
    }
}
