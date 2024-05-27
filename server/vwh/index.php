<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/.private/assembler.php';

$a = new Assembler('Career Day 2024<br>Interviews');

$a->body_main_id = 'index-main';

$a->body_main = function() { ?>
	<h1>What is this place?</h1>

	<p>Here you can stay up to date with all interviews happening during the event. This includes currently happening interviews, interviews in each company's queue, and their related states at any moment.</p>
	<p>Furthermore, we can recommend to you positions and companies that fit you best based on your resume.</p>
	<p>Navigate from the menu on top to explore its feature!</p>

	<h1>Interview where?</h1>

	<p>TBD</p>

	<h1>Share</h1>
	
	<p>Use the QR code...</p>
	<script src="/script/utilities.js"></script>
	<img id="current_url_qr" alt="...it did not generated properly...">
	<script>qr_generate(window.location.href, document.getElementById('current_url_qr'));</script>
	<p>...or <a href="#3werf" id="3werf">copy the link</a> and send it!</p>
<?php };

$a->assemble();
