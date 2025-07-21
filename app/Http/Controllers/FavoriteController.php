<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    // Получить список избранных текущего пользователя
    public function index()
    {
        $favorites = Favorite::where('user_id', auth()->id())
            ->with('property.photos') // Загрузка property и его photos
            ->get();

        return response()->json($favorites);
    }

    // Добавить объект в избранное
    public function store(Request $request)
    {
        $request->validate([
            'property_id' => 'required|exists:properties,id',
        ]);

        $favorite = Favorite::firstOrCreate([
            'user_id' => auth()->id(),
            'property_id' => $request->property_id,
        ]);

        return response()->json($favorite, 201);
    }

    // Удалить объект из избранного
    public function destroy($id)
    {
        $favorite = Favorite::where('user_id', auth()->id())
            ->where('property_id', $id)
            ->first();

        if (!$favorite) {
            return response()->json(['message' => 'Не найдено'], 404);
        }

        $favorite->delete();

        return response()->json(['message' => 'Удалено из избранного']);
    }
}
