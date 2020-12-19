<?php

namespace App\Http\Controllers;

use App\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private $validationRules = [
        'designation' => 'required|string',
        'prix_de_vente' => 'required|numeric|min:0',
        'stock_initial' => 'required|integer|min:0',
        'stock_actuel' => 'required|numeric|min:0',
        'prix_dachat' => 'required|integer|min:0',
        'montant' => 'required|numeric|min:0',
        // 'image' => 'image|mimes:jpeg,png,jpg|max:2048'
    ];

    // return all products
    public function index()
    {
        $products = Product::all();

        foreach ($products as $product) {
            $gain = ($product->stock_initial - $product->stock_actuel) * ($product->prix_de_vente - $product->prix_de_dachat);
            $product->gain = $gain;
        }

        return response()->json(['products' => $products], 200);
    }

    // return the product with the given id
    public function read(Request $request)
    {
        $this->validate($request, ['productId' => 'required|numeric']);

        $productId = $request->input('productId');
        $product = $this->findProductByID($productId);

        if (!$product) {
            return response()->json(['message' => 'PRODUCT NOT FOUND!'], 400);
        }

        return response()->json(['product' => $product], 200);
    }

    public function create(Request $request)
    {
        $this->validate($request, $this->validationRules);

        $product = Product::create([
            'designation' => $request->input('designation'),
            'prix_de_vente' => $request->input('prix_de_vente'),
            'stock_initial' => $request->input('stock_initial'),
            'stock_actuel' => $request->input('stock_actuel'),
            'prix_de_dachat' => $request->input('prix_dachat'),
            'montant' => $request->input('montant'),
            'image' => ''
        ]);

        // $imageName = 'product_' . $product->id . '.' . request()->image->getClientOriginalExtension();
        // request()->image->move(public_path('images'), $imageName);
        // $product->image = env('IMAGES_DIRECTORY') . '/' . $imageName;

        $product->save();

        return response()->json(['message' => 'CREATED', 'product' => $product], 200);
    }

    public function update(Request $request)
    {
        $this->validate($request, ['productId' => 'required|numeric']);

        $productId = $request->input('productId');
        $product = Product::find($productId);

        if ($product) {
            $product->designation = $request->input('designation') != null ? $request->input('designation') : $product->designation;
            $product->prix_de_vente = $request->input('prix_de_vente') != null ? $request->input('prix_de_vente') : $product->prix_de_vente;
            $product->stock_initial = $request->input('stock_initial') != null ? $request->input('stock_initial') : $product->stock_initial;
            $product->stock_actuel = $request->input('stock_actuel') != null ? $request->input('stock_actuel') : $product->stock_actuel;
            $product->prix_de_dachat = $request->input('prix_de_dachat') != null ? $request->input('prix_de_dachat') : $product->prix_de_dachat;
            $product->montant = $request->input('montant') != null ? $request->input('montant') : $product->montant;

            // replace the product image in /images directory
            if ($request->image) {
                $imageSrc = $product->image;
                $image = $request->image;
                $imageName = str_replace(env('IMAGES_DIRECTORY'), '', $imageSrc);
                $image->move(public_path('images'), $imageName);
            }

            $product->save();

            return response()->json(['message' => 'UPDATED', 'product' => $product], 200);
        }

        return response()->json(['message' => 'PRODUCT NOT FOUND!'], 400);
    }

    public function delete(Request $request)
    {
        $this->validate($request, ['productId' => 'required|numeric']);

        $productId = $request->input('productId');

        if ($this->deleteProduct($productId)) {
            return response()->json(['message' => 'PRODUCT DELETED!'], 200);
        }

        return response()->json(['message' => 'PRODUCT NOT FOUND!'], 400);
    }

    public function multipleDelete(Request $request)
    {
        $this->validate($request, ['products' => 'required']);

        $products = $request->input('products');
        $deleted = true;
        $output = array();

        foreach ($products as $product) {
            if (!$this->deleteProduct($product)) {
                $deleted = false;
                array_push($output, $product);
            }
        }

        if ($deleted) {
            return response()->json(['message' => 'PRODUCTS DELETED!'], 200);
        }

        return response()->json([
            'message' => 'SOME PRODUCTS WERE NOT FOUND!',
            'products not found' => $output
        ], 400);
    }

    public function buyProduct(Request $request)
    {
        $this->validate($request, [
            'productId' => 'required|numeric',
            'quantity' => 'required|numeric|min:1',
        ]);

        $productId = $request->input('productId');
        $quantity = $request->input('quantity');

        $product = $this->findProductByID($productId);

        if (!$product) {
            return response()->json(['message' => 'PRODUCT NOT FOUND!'], 400);
        }

        if ($product->stock_actuel < $quantity) {
            return response()->json(['message' => 'QUANITY IN STOCK IS LESS THAN THE ONE REQUESTED!'], 400);
        }
        $this->modifyProductQuantity($product, $quantity, "buy");

        return response()->json(['message' => 'OPERARTION DONE!'], 200);
    }

    public function sellProduct(Request $request)
    {
        $this->validate($request, [
            'productId' => 'required|numeric',
            'quantity' => 'required|numeric|min:1',
        ]);

        $productId = $request->input('productId');
        $quantity = $request->input('quantity');

        $product = $this->findProductByID($productId);

        if (!$product) {
            return response()->json(['message' => 'PRODUCT NOT FOUND!'], 400);
        }

        if ($product->stock_actuel < $quantity) {
            return response()->json(['message' => 'QUANITY IN STOCK IS LESS THAN THE ONE REQUESTED!'], 400);
        }
        $this->modifyProductQuantity($product, $quantity, "sell");

        return response()->json(['message' => 'OPERARTION DONE!'], 200);
    }

    public function readOperations(Request $request)
    {
        $this->validate($request, ['productId' => 'required|numeric']);

        $product = $this->findProductByID($request->input('productId'));

        if (!$product) {
            return response()->json(['message' => 'PRODUCT NOT FOUND!'], 400);
        }

        $operations = $product->operations;

        return response()->json(['operations' => $operations], 200);
    }

    private function modifyProductQuantity($product, $quantity, $operation)
    {
        $montant = 0;
        if ($operation == "buy") {
            $product->stock_actuel += $quantity;
            $montant = $quantity * $product->prix_de_dachat;
        } else if ($operation == "sell") {
            $product->stock_actuel -= $quantity;
            $montant = $quantity * $product->prix_de_vente;
        }

        $product->operations()->create([
            'type' => $operation,
            'montant' => $montant,
        ]);

        $product->save();
    }

    private function findProductByID($id)
    {
        $product = Product::find($id);

        $gain = ($product->stock_initial - $product->stock_actuel) * ($product->prix_de_vente - $product->prix_de_dachat);
        $product->gain = $gain;

        $product->load('operations');

        return $product ? $product : null;
    }

    private function deleteProduct($id)
    {
        $product = $this->findProductByID($id);

        if ($product) {
            $imagePath = str_replace(env('IMAGES_DIRECTORY'), public_path('images'), $product->image);
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            $product->delete();

            return true;
        }

        return false;
    }
}
