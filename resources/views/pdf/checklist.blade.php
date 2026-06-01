<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Checklist {{ $set->code }}</title>
    <style>
        body { font-family: sans-serif; color: #222; margin: 0; padding: 0; }
        
        /* Cabecera compacta */
        .header { text-align: center; margin-bottom: 15px; border-bottom: 2px solid #222; padding-bottom: 5px; }
        .header h1 { margin: 0; font-size: 20px; text-transform: uppercase; }
        .header p { margin: 0; font-size: 12px; color: #666; }

        /* Magia de las 3 columnas */
        .column-wrapper {
            column-count: 3; /* Tres columnas */
            column-gap: 30px; /* Espacio entre columnas */
        }

        /* Cada carta es una fila finita */
        .card-row {
            display: flex;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            padding: 4px 0;
            font-size: 11px; /* Letra pequeña para que quepan muchas */
            page-break-inside: avoid; /* Evita que una carta se corte a la mitad al cambiar de página */
        }

        /* La cajita para tachar */
        .checkbox {
            min-width: 14px; /* Cambiamos width por min-width para que pueda crecer */
            height: 14px;
            padding: 0 2px;  /* Un poco de aire lateral para los números */
            border: 1px solid #333;
            border-radius: 2px;
            display: inline-flex; 
            align-items: center;     
            justify-content: center; 
            font-size: 10px;
            font-weight: bold;
            color: #111; 
            background-color: #fff;
            flex-shrink: 0; 
            margin-right: 8px; /* 🚀 Separación con el ID de la carta */
        }

        /* Textos */
        .card-id { font-weight: bold; width: 65px; flex-shrink: 0; }
        .card-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-grow: 1; }
        
        /* Etiqueta visual para las paralelas */
        .parallel-badge {
            background-color: #222;
            color: #fff;
            font-size: 8px;
            padding: 1px 4px;
            border-radius: 3px;
            margin-left: 5px;
            font-weight: bold;
        }

        .promo-banner {
            background-color: #f8f9fa;
            border: 1px dashed #666;
            text-align: center;
            padding: 8px;
            margin-bottom: 15px;
            font-size: 11px;
            color: #444;
            border-radius: 4px;
        }
        .promo-banner strong {
            color: #111;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>{{ $set->name }}</h1>
        <p>Checklist Oficial - Colección [{{ $set->code }}]</p>
    </div>

    {{-- 🚀 MAGIA: El banner publicitario para los invitados --}}
    @if(!$isLoggedIn)
        <div class="promo-banner">
            💡 <strong>¿Quieres tu checklist personalizado?</strong> Regístrate o inicia sesión en la web y descarga este PDF con todas las cartas de tu colección marcadas automáticamente.
        </div>
    @endif

    <div class="column-wrapper">
        @foreach($cards as $card)
            <div class="card-row">
                
                {{-- 🚀 MAGIA: Si existe en el array, mostramos la cantidad. Si no, caja vacía --}}
                @if(isset($ownedCards[$card->id]))
                    <div class="checkbox">{{ $ownedCards[$card->id] }}</div> 
                @else
                    <div class="checkbox"></div>  
                @endif

                <div class="card-id">{{ $card->card_number }}</div>
                <div class="card-name">{{ $card->name }}</div>
                
                @if($card->unique_id !== $card->card_number)
                    <div class="parallel-badge">AA</div>
                @endif
            </div>
        @endforeach
    </div>

</body>
</html>