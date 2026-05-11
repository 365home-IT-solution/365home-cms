<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liên Hệ - Golden Bee IT Solutions</title>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Open Sans', sans-serif;
            background: linear-gradient(135deg, #FFF3E0 0%, #FFF8E1 100%);
        }

        .container {
            margin: 0 auto;
            max-width: 64rem;
            background-color: #ffffff;
            border-radius: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        /* Header content */
        .header-content {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            z-index: 10;
        }

        .company-title {
            font-size: 1.5rem;
            font-weight: bold;
            letter-spacing: -0.025em;
        }

        .company-slogan {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
            margin-top: 0.5rem;
        }

        /* Contact links */
        .contact-link {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 600;
            text-decoration: none;
            color: #ffffff;
            transition: color 0.3s;
        }

        .contact-link:hover {
            color: #000000;
            transform: scale(1.05);
        }

        /* Main content */
        .main-content {
            padding: 3rem 4rem;
        }

        /* Contact section */
        .contact-section {
            background-color: #f9fafb;
            border-radius: 0.75rem;
            padding: 2rem;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .icon {
            color: #ea580c;
            width: 2rem;
            height: 2rem;
        }

        .contact-label {
            font-weight: 600;
            font-size: 1.125rem;
        }

        .contact-link-orange {
            color: #ea580c;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: color 0.3s;
        }

        .contact-link-orange:hover {
            color: #9a3412;
        }

        /* Message section */
        .message-section {
            background-color: #ffffff;
            border: 1px solid #ffedd5;
            border-radius: 0.75rem;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .section-title {
            font-size: 1.875rem;
            font-weight: bold;
            margin-bottom: 1.5rem;
        }

        .message-content {
            color: #374151;
            line-height: 1.625;
        }

        .message-label {
            font-weight: 600;
            color: #c2410c;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .gradient-bg {
                flex-direction: column;
                text-align: center;
                padding: 2rem;
            }

            .contact-section {
                grid-template-columns: 1fr;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>

<body style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem;">
    <div class="container">

        <div class="main-content">
            <div class="message-section">
                <h2 class="section-title message-label">
                    <span style="color:#000000">Thông tin gửi từ form: </span>
                    {{ $subject }}
                </h2>
                <div class="message-content">
                    <p>
                        {!! $messageBody !!}
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
