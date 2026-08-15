<?php

namespace App\Command;

use App\Entity\Item;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-excel',
    description: 'Вкарва артикулите от кеи.xlsx в базата',
)]
class ImportExcelCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = 'public/kei.xls';

        if (!file_exists($filePath)) {
            $io->error('Файлът кеи.xls не е намерен!');
            return Command::FAILURE;
        }

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getSheetByName('Лист1') ?? $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // Изчистваме старите записи преди новия импорт
        $this->entityManager->createQuery('DELETE FROM App\Entity\Item i')->execute();

        $count = 0;

        foreach ($rows as $index => $row) {
            // Пропускаме първите 2 реда (заглавията)
            if ($index < 2) continue;

            $code = $row[0] ?? null;
            $name = $row[1] ?? null;
            $unit = $row[2] ?? 'бр.';      // Мярка (колона 2 според твоята таблица: чф., кг., дм2 и т.н.)
            $quantity = $row[9] ?? 0;       // Количество (колона 9)
            $price = $row[11] ?? null;      // Сума/Цена (колона 11)

            // Ако няма име, прескачаме
            if (empty($name)) continue;

            $item = new Item();
            $item->setCode((string)$code);
            $item->setName((string)$name);
            $item->setUnit(trim((string)$unit));
            $item->setQuantity((float)$quantity);
            $item->setPrice((float)$price);

            $this->entityManager->persist($item);
            $count++;
        }

        $this->entityManager->flush();
        $io->success("Готово! Успешно импортирахме $count артикула с техните мерни единици.");

        return Command::SUCCESS;
    }
}
