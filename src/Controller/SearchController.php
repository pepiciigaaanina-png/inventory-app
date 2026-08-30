<?php

namespace App\Controller;

use App\Entity\Item;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Cloudinary;
use Cloudinary\Uploader;

class SearchController extends AbstractController
{
    #[Route('/search', name: 'app_search')]
    public function index(Request $request, ItemRepository $itemRepository): Response
    {
        $query = trim($request->query->get('q', ''));

        // Взимаме всички артикули от базата
        $allItems = $itemRepository->findAll();

        if ($query) {
            // Търсенето: правим го на малки букви с поддръжка на кирилица (UTF-8)
            $search = mb_strtolower($query, 'UTF-8');

            $items = array_filter($allItems, function ($item) use ($search) {
                // Взимаме името и кода и ги правим на малки букви
                $name = mb_strtolower($item->getName() ?? '', 'UTF-8');
                $code = mb_strtolower($item->getCode() ?? '', 'UTF-8');

                // Проверяваме дали търсената дума се съдържа в името или кода
                return str_contains($name, $search) || str_contains($code, $search);
            });

            // Преиндексираме масива, за да е правилен JSON списък
            $items = array_values($items);
        } else {
            $items = $allItems;
        }

        // Ако заявката идва от JavaScript (AJAX) - връщаме JSON
        if ($request->headers->get('Accept') === 'application/json') {
            return $this->json($items);
        }

        // Ако е нормално зареждане на страницата - връщаме Twig шаблона
        return $this->render('search/index.html.twig', [
            'items' => $items,
            'query' => $query,
        ]);
    }
    #[Route('/api/upload-item-image', name: 'api_upload_item_image', methods: ['POST'])]
    public function uploadItemImage(Request $request, ItemRepository $itemRepository, EntityManagerInterface $entityManager): Response
    {
        $uploadedFile = $request->files->get('image');
        $itemId = $request->request->get('item_id'); // Взимаме ID-то на артикула

        if (!$uploadedFile) {
            return $this->json(['error' => 'Няма прикачен файл'], 400);
        }

        try {
            // 1. Конфигурираме Cloudinary
            Cloudinary::config_from_url($_ENV['CLOUDINARY_URL'] ?? $_SERVER['CLOUDINARY_URL']);

            // 2. Качваме в облака
            $uploadResult = Uploader::upload($uploadedFile->getPathname(), [
                'folder' => 'inventory_diploma',
                'resource_type' => 'image'
            ]);

            $imageUrl = $uploadResult['secure_url'];

            // 3. Ако е подадено item_id, записваме линка в базата към съответния артикул!
            if ($itemId) {
                $item = $itemRepository->find($itemId);
                if ($item) {
                    $item->setImageUrl($imageUrl);
                    $entityManager->flush();
                }
            }

            return $this->json([
                'success' => true,
                'url' => $imageUrl,
                'message' => 'Снимката е качена успешно и е закрепена към артикула!'
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Грешка при качване в Cloudinary: ' . $e->getMessage()
            ], 500);
        }
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
        $uploadedFile = $request->files->get('image');
        $base64Image = null;
        $mimeType = 'image/jpeg';

        if ($uploadedFile) {
            // Четем файла директно от временната папка в паметта, БЕЗ да го запазваме на диска!
            $base64Image = base64_encode(file_get_contents($uploadedFile->getPathname()));
            $mimeType = $uploadedFile->getMimeType();
        } else {
            // Ако няма файл, връщаме грешка
            return $this->json(['error' => 'Няма прикачен файл за анализ!'], 400);
        }

        try {
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
                    'scanned_unit' => $scannedItem['unit'] ?? 'бр.',
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
    #[Route('/api/suggest-location', name: 'api_suggest_location', methods: ['GET'])]
    public function suggestLocation(Request $request, \App\Service\GeminiService $geminiService): Response
    {
        $itemName = $request->query->get('item');

        if (!$itemName) {
            return $this->json(['error' => 'Липсва артикул'], 400);
        }

        // --- ИЗЧИСТВАНЕ НА ИМЕТО ---
        $parts = explode('\\', $itemName);
        $cleanItemName = count($parts) > 1 ? trim($parts[1]) : trim($itemName);
        $cleanItemName = preg_replace('/^[\d\.\s]+/u', '', $cleanItemName);

        try {
            // ТУК Е МАГИЯТА: Даваме на AI-то изключително точни инструкции как да мисли!
            $prompt = "Ти си главен военен логистик. Състави много детайлно и реалистично основание за изписване на следния материал: '$cleanItemName'.\n" .
                "ИНСТРУКЦИИ ЗА АНАЛИЗ:\n" .
                "1. Внимателно провери името за ЦВЯТ! Ако има 'кафява' -> напиши, че е за освежаване на врати в сграда №23. Ако е 'бяла' -> за АТТ техника или стаи. Ако е 'синя' -> за резервоар за вода (водоноска). Ако е 'жълта' -> за външни саксии и бордюри.\n" .
                "2. Ако е авточаст, корда, масло, свещ -> напиши, че е за ремонт на АТТ техника (напр. камиони ЗиЛ-131, УАЗ или моторни коси Хускварна).\n" .
                "3. Ако е ВиК/Ел/Строителен -> посочи конкретен обект: КТП, щаба, сграда №23, караулно помещение, периметрова ограда.\n" .
                "4. Задължително накрая завърши изречението с думите 'във в.ф.'.\n" .
                "5. Започни директно с една от фразите: 'вложени за...', 'използвани за...' или 'изразходвани за...'.\n" .
                "Дължина: около 15 до 30 думи. Върни само готовия текст, без кавички и без никакви допълнителни обяснения.";

            // Викаме Gemini
            $rawResult = $geminiService->analyzeImage($prompt, null, null);
            $suggestion = trim(str_replace(['```json', '```', '"', '[', ']'], '', $rawResult));

            // Увеличихме лимита на 250 символа, за да има място за подробностите!
            if (empty($suggestion) || mb_strlen($suggestion) > 250) {
                throw new \Exception("AI върна невалиден отговор");
            }

        } catch (\Throwable $e) {
            // ФОЛБЕК (Ако няма интернет): И тук добавихме "във в.ф." и повече детайли.
            $nameLower = mb_strtolower($cleanItemName, 'UTF-8');

            if (str_contains($nameLower, 'тръба') || str_contains($nameLower, 'сифон') || str_contains($nameLower, 'кран') || str_contains($nameLower, 'тапа')) {
                $suggestion = "вложени за авариен ремонт на водопроводната инсталация в сграда №23 във в.ф.";
            } elseif (str_contains($nameLower, 'боя') || str_contains($nameLower, 'латекс')) {
                $suggestion = "изразходвани за боядисване и козметично освежаване на помещенията и техниката във в.ф.";
            } elseif (str_contains($nameLower, 'масло') || str_contains($nameLower, 'ауспух') || str_contains($nameLower, 'гума') || str_contains($nameLower, 'свещ')) {
                $suggestion = "вложени за техническо обслужване и ремонт на АТТ техника (камиони ЗиЛ) във в.ф.";
            } elseif (str_contains($nameLower, 'прекъсвач') || str_contains($nameLower, 'кабел') || str_contains($nameLower, 'контакт')) {
                $suggestion = "използвани за възстановяване на електрозахранването в главното табло на караулното помещение във в.ф.";
            } else {
                $suggestion = "вложени за осигуряване на ежедневната експлоатационна дейност във в.ф.";
            }
        }

        return $this->json(['suggestion' => $suggestion]);
    }
}
