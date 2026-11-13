<?php

namespace App\Models;

use Database\Factories\TicketMensajeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMensaje extends Model
{
    /** @use HasFactory<TicketMensajeFactory> */
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'cuerpo',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
