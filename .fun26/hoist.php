<?php
if (class_exists("A", false)) { echo "hoisted->return\n"; return; }
class A {}
echo "continued\n";
