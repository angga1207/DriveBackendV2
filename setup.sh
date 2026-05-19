#!/bin/bash

# Drive OI Backend V2 - Complete Setup Script
# This script will create all necessary files for the backend

echo "🚀 Setting up Drive OI Backend V2..."

# Navigate to project directory
cd "$(dirname "$0")"

echo "📦 Step 1: Installing dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "🔑 Step 2: Generating application key..."
php artisan key:generate --force

echo "📝 Step 3: Creating Controllers..."

# Auth Controller
php artisan make:controller API/AuthController
# Data Controller
php artisan make:controller API/DataController
# Shared Data Controller
php artisan make:controller API/SharedDataController
# User Controller
php artisan make:controller API/UserController
# Profile Controller
php artisan make:controller API/ProfileController
# Search Controller
php artisan make:controller API/SearchController
# Upload Controller
php artisan make:controller API/UploadController

echo "📋 Step 4: Creating Form Requests..."
php artisan make:request LoginRequest
php artisan make:request StoreDataRequest
php artisan make:request UpdateDataRequest
php artisan make:request ShareDataRequest
php artisan make:request UploadFileRequest
php artisan make:request CreateFolderRequest

echo "🔧 Step 5: Creating Services..."
mkdir -p app/Services
# Services will be created manually

echo "⚡ Step 6: Creating Jobs..."
php artisan make:job TransferFileToGoogleDrive
php artisan make:job ProcessUploadBatch
php artisan make:job CleanupExpiredShares

echo "📢 Step 7: Creating Events..."
php artisan make:event FileUploaded
php artisan make:event FileShared
php artisan make:event UploadCompleted

echo "👂 Step 8: Creating Listeners..."
php artisan make:listener SendUploadNotification --event=UploadCompleted
php artisan make:listener LogActivityListener

echo "👀 Step 9: Creating Observers..."
php artisan make:observer Data Observer --model=Data

echo "🛡️ Step 10: Creating Middleware..."
php artisan make:middleware CheckUserAccess
php artisan make:middleware ValidateDataOwnership

echo "🗄️ Step 11: Publishing vendor configs..."
php artisan vendor:publish --tag=sanctum-config
php artisan vendor:publish --tag=permission-config
php artisan vendor:publish --tag=activitylog-config

echo "📊 Step 12: Database setup..."
echo "⚠️  WARNING: Make sure your .env is configured correctly!"
read -p "Do you want to run migrations? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]
then
    echo "Running migrations..."
    php artisan migrate --force
fi

echo "🎨 Step 13: Optimizing..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "✅ Setup complete!"
echo ""
echo "📌 Next steps:"
echo "1. Configure your .env file with database credentials"
echo "2. Configure Google Drive API credentials"
echo "3. Configure Firebase/FCM credentials"
echo "4. Run: php artisan queue:work (for background jobs)"
echo "5. Run: php artisan serve (to start the server)"
echo ""
echo "📚 Read README.md for detailed documentation"
