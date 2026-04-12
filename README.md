# atptour

A PHP scraper for collecting historical match results and player data from the ATP Tour website. Supports both single-threaded and multi-threaded execution for faster data collection.

## Overview

Parses the ATP Tour results archive and stores structured match and player data in a MySQL database. Designed for sports data analysis, betting research, or building historical datasets.

**Collected data includes:** match results, player rankings, tournament info, scores by set.

## Stack

`PHP 7.0+` `MySQL` `cURL`

## Structure

```
atptour/
├── ScraperClass.php        # Core scraping logic
├── ResultsScraper.php      # Results parsing
├── run_results.php         # Single-thread runner
├── run_results_multi.php   # Multi-thread runner
├── export_players.php      # Player data export
├── export_results.php      # Results export
└── db.php                  # Database connection
```

## Installation

```bash
git clone https://github.com/gnigmatullin/atptour.git
cd atptour
```

Configure your database connection in `db.php`, then run:

```bash
# Single-threaded
php run_results.php

# Multi-threaded (faster for large date ranges)
php run_results_multi.php
```

Export collected data:

```bash
php export_players.php
php export_results.php
```

## Related projects

For sports data processing at scale — including AI-based team name matching across 100K+ aliases using Claude API — see my [Upwork portfolio](https://www.upwork.com/freelancers/gazizn).

---

[LinkedIn](https://www.linkedin.com/in/gaziz-nigmatullin/) · [Upwork](https://www.upwork.com/freelancers/gazizn)
