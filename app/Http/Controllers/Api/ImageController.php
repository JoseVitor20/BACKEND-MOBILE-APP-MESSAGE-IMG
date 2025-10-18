<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    /**
     * Lista as URLs de todas as imagens no bucket público.
     * * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Define o disco de armazenamento como 's3' (seu Object Storage/Bucket)
        $disk = Storage::disk('s3');
        
        // Lista todos os arquivos (imagens) no diretório raiz do bucket
        // Se suas imagens estiverem em subpastas, use $disk->allFiles('caminho/para/imagens')
        $files = $disk->allFiles('');

        $imageUrls = [];
        
        foreach ($files as $filePath) {
            // A visibilidade é pública, então usamos url() para gerar o link direto do CDN/Bucket.
            $url = $disk->url($filePath);
            
            $imageUrls[] = $url;
        }

        return response()->json([
            'status' => 'success',
            'count' => count($imageUrls),
            'images' => $imageUrls
        ]);
    }
}   