# pthirdparty – Order Desk

Folder layout = the layout on the server (`public_html/` is the repository root):

    pthirdparty/orders/            the pages (index.php, orders.php, login.php, ...)
    pthirdparty/orders/includes/   code only (bootstrap, header, footer, *_lib.php) + .htaccess that denies direct web access
    pthirdparty/orders/assets/     css / js
    pthirdparty/orders/config.php  NOT in Git (database + keys live only on the server)
    pthirdparty/orders/uploads/    NOT in Git (customers' payment proofs)

The page address is https://pthirdparty.com/pthirdparty/orders/
