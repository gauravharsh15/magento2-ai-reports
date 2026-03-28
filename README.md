# Magento 2 AI SQL Reports

An enterprise-grade Adobe Commerce (Magento 2) module that allows administrators to generate and execute complex database reports using Natural Language via AI. 

Instead of writing complex EAV joins manually, simply ask: *"Show me the top 5 customers by grand total spent this month"* and the module will write the SQL, execute it safely, and present the data in a downloadable CSV grid.

## Features
* **Multi-LLM Support:** Native integrations for OpenAI (ChatGPT), Anthropic (Claude), and Google (Gemini).
* **Self-Correcting Execution:** If the AI generates an invalid SQL query that throws a database error, the module automatically catches the error and feeds it back to the AI for self-correction (up to 3 retries).
* **Strict Security:** Built-in regex safeguards strictly enforce `SELECT`-only operations, blocking all destructive commands (`UPDATE`, `DROP`, `DELETE`, etc.).
* **CSV Export:** One-click download of generated datasets.

## Requirements
* Adobe Commerce / Magento Open Source 2.4.7 or higher
* PHP 8.2 or 8.3
* An active API key for OpenAI, Anthropic, or Gemini.

## Installation

Install via Composer:

```bash
composer require gauravharsh/module-ai-reports
bin/magento module:enable Gaurav_AiReports
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush