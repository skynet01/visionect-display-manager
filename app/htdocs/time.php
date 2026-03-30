<?php
echo "Php reported timezone is: -> ";
echo date_default_timezone_get() . "<br/>";

echo ini_get('date.timezone') . "<br/>";
$date = date('m/d/Y h:i:s a', time());
echo $date;
?>