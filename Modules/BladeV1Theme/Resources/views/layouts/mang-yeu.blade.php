<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: #202124;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .error-container {
            max-width: 600px;
            width: 100%;
            padding: 0 20px;
            box-sizing: border-box;
            /* Xóa margin-top vì đã căn giữa bằng flexbox */
        }

        .error-icon {
            width: 48px;
            height: 48px;
            margin-bottom: 16px;
            color: #202124;
        }

        .error-title {
            color: #202124;
            font-size: 1.25rem;
            font-weight: 500;
            margin-bottom: 16px;
        }

        .error-subtitle {
            color: #5f6368;
            margin-bottom: 24px;
        }

        .error-items {
            color: #5f6368;
            line-height: 1.8;
            margin-left: 24px;
            margin-bottom: 24px;
            padding-left: 0;
        }

        .error-code {
            color: #5f6368;
            font-size: 0.875rem;
            margin-bottom: 24px;
        }

        .button-container {
            display: flex;
            justify-content: flex-start;
            gap: 12px;
        }

        .reload-button {
            background: #1a73e8;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            font-weight: 500;
            cursor: pointer;
        }

        .details-button {
            background: white;
            color: #1a73e8;
            border: 1px solid #dadce0;
            padding: 8px 16px;
            border-radius: 4px;
            font-weight: 500;
            cursor: pointer;
        }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>