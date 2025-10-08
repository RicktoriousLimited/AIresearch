<?php

declare(strict_types=1);

namespace App\Scraping;

interface ScraperInterface
{
    public function scrape(string $url): ScrapeResult;
}

