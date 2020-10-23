<?php

/**
 * This file has been auto-generated by Airam.
 */
?>

<body>
    <style type="text/css">
        body {
            display: flex;
            flex-flow: column nowrap;
        }

        main {
            background-color: white;
            position: relative;
            display: block;
            margin: auto;
            box-shadow: grey 0px 3px 5px 0;
            padding: 5px;
            width: 500px;
        }

        main .header {
            display: flex;
            flex-flow: row nowrap;
            align-items: flex-start;
            align-content: space-around;
        }

        main .header strong {
            background-color: #F44336;
            color: white;
            border: 20px;
            display: inline-block;
            padding: 5px;
            font-size: 14pt;
        }

        main .header .text-container {
            display: flex;
            flex-flow: column nowrap;
            padding: 0 5px;
        }

        main .header .text-container .title {
            font-size: 12pt;
        }

        main .header .text-container .subtitle {
            font-size: 9pt;
            color: #757575;
        }

        main .header .text-container .subtitle .note {
            text-decoration: underline;
            align-self: baseline;
            cursor: pointer;
            color: #2196F3;
            font-size: 7pt;

        }

        main p.description {
            font-weight: 400;
            font-size: 12px;
        }
    </style>
    <main>
        <div class="header">
            <strong> <?= $code; ?> </strong>
            <div class="text-container">
                <b class="title">
                    <?= (isset($title) ? $title : "HTTP Error"); ?>
                </b>
                <small class="subtitle">
                    <span><?= $message; ?></span><br>
                    <span class="note"><?= ($note ?: "") ?></span>
                </small>
            </div>
        </div>

        <p class="description"><?= $description; ?></p>
    </main>
</body>