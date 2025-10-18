Step 1:
- php tool that loads data from a list of given urls and grabs data from these pages and saves it
- database table(s) that hold product base data incl. urls
- database table that holds historical data (price and seller at date fetched)
- config in db where we can define the patterns of data we are searching for by host name; e.g. for urls that start with https://host1.tld we would look for different data than for urls starting with https://host2.tld 

Step 2:
- interface to bulk add data; one text area field, where the user can copy data in tab seperated format to add / update product data
- product data overview that can be filtered
- where product data is shown in relation, e.g. parent and child products (= sizes)

Stack:
- php
- mysql
- config in .env files
- use composer
- logging with monolog
- twig for templating
- delight-im/auth for user authentication
- TailwindCSS / shadcn/ui

Fields in the product DB:

product_id - varchar, 50
parent_id - varchar, 50
sku - varchar, 50
ean - varchar, 20
site - varchar, 50
site_product_id - varchar, 50
price - float, 2 digits
uvp - float, 2 digits
site_status - varchar, 20
url - varchar, 250