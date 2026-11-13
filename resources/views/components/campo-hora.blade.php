@props([
    'id',
    'name',
    'valor' => '',
    'min' => null,
    'max' => null,
    'required' => true,
])

<x-hora
    :id="$id"
    :name="$name"
    :valor="$valor"
    :min="$min"
    :max="$max"
    :required="$required"
/>
