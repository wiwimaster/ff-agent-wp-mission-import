( function ( wp ) {
	if ( ! wp || ! wp.blocks ) { return; }
	const { registerBlockType } = wp.blocks;
	const { useSelect } = wp.data;
	const InspectorControls = ( wp.blockEditor || wp.editor ).InspectorControls;
	const { PanelBody, RangeControl, CheckboxControl, Button, TextControl, ToggleControl, SelectControl } = wp.components;
	const el = wp.element.createElement;

	registerBlockType( 'ffami/mission-table-latest', {
		edit: function ( props ) {
			const perPage = props.attributes.perPage || 20;
			// Collect existing mission types (best-effort) from loaded posts (may be limited) – fallback list.
			const existingPosts = useSelect( function( select ){ return select('core').getEntityRecords('postType','mission',{ per_page: 100 }); }, [] ) || [];
			var discoveredTypes = {};
			existingPosts.forEach(function(p){
				if (p && p.meta && p.meta.ffami_mission_type) { discoveredTypes[p.meta.ffami_mission_type]=true; }
			});
			var typeOptions = Object.keys(discoveredTypes);
			if (!typeOptions.length) { typeOptions = ['Technische Hilfeleistung','Brand','First Responder','Sonstiges']; }
			const allColumns = [
				{ key: 'date', label: 'Datum' },
				{ key: 'title', label: 'Titel' },
				{ key: 'type', label: 'Typ' },
				{ key: 'duration', label: 'Dauer' },
				{ key: 'persons', label: 'Personen' },
				{ key: 'location', label: 'Ort' }
			];
			var columns = props.attributes.columns && props.attributes.columns.length ? props.attributes.columns : allColumns.map( c => c.key );
			function toggleColumn( key ) {
				var next;
				if ( columns.includes( key ) ) {
					next = columns.filter( c => c !== key );
				} else {
					next = columns.concat( [ key ] );
				}
				props.setAttributes( { columns: next } );
			}
			function moveColumn( key, dir ) {
				var idx = columns.indexOf( key );
				if ( idx === -1 ) return;
				var target = idx + dir;
				if ( target < 0 || target >= columns.length ) return;
				var arr = columns.slice();
				var tmp = arr[target];
				arr[target] = arr[idx];
				arr[idx] = tmp;
				props.setAttributes( { columns: arr } );
			}
			function human( key ) {
				var f = allColumns.find( c => c.key === key );
				return f ? f.label : key;
			}
			// Vorschau lädt nur Titel-Spalte: nutzt perPage als Anzahl
			const posts = useSelect( function ( select ) {
				return select( 'core' ).getEntityRecords( 'postType', 'mission', { per_page: perPage } ) || [];
			}, [ perPage ] );

			const listItems = posts.map( function ( p ) {
				var title = ( p.title && p.title.rendered ) ? p.title.rendered : '(Ohne Titel)';
				return el( 'li', { key: p.id }, title );
			} );
			if ( ! posts.length ) {
				listItems.push( el( 'li', { key: 'empty' }, '(Keine Einsätze gefunden)' ) );
			}

			return el( wp.element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: 'Einstellungen', initialOpen: true },
						el( RangeControl, {
							label: 'Einträge (pro Seite oder insgesamt)',
							value: perPage,
							min: 1,
							max: 100,
							onChange: function ( value ) { props.setAttributes( { perPage: value } ); }
						} ),
						el( ToggleControl, {
							label: 'Paginierung aktivieren',
							checked: !!props.attributes.pagination,
							onChange: function ( value ) { props.setAttributes( { pagination: value } ); }
						} ),
						!!props.attributes.pagination && el( SelectControl, {
							label: 'Paginierungsmodus',
							value: props.attributes.paginationMode || 'simple',
							options: [
								{ label: 'Einfach (1,2,3, ...)', value: 'simple' },
								{ label: 'Jahre + Seiten', value: 'year' }
						],
							onChange: function ( value ) { props.setAttributes( { paginationMode: value } ); }
						} ),
						!!props.attributes.pagination && el( SelectControl, {
							label: 'Position Paginierung',
							value: props.attributes.paginationPosition || 'below',
							options: [
								{ label: 'Unterhalb', value: 'below' },
								{ label: 'Oberhalb', value: 'above' },
								{ label: 'Oben & Unten', value: 'both' }
						],
							onChange: function ( value ) { props.setAttributes( { paginationPosition: value } ); }
						} ),
						!!props.attributes.pagination && el( TextControl, {
							label: 'Paginierungs-ID (für mehrere Tabellen auf Seite)',
							value: props.attributes.paginationId || '1',
							onChange: function ( value ) { props.setAttributes( { paginationId: value.replace(/[^a-zA-Z0-9_-]/g,'') || '1' } ); },
							help: 'Muss auf einer Seite eindeutig sein.'
						} ),
						el( 'div', { style: { marginTop: '1em' } },
							el( 'strong', null, 'Einsatztypen filtern' ),
							typeOptions.map(function(t){
								var checked = (props.attributes.filterTypes || []).includes(t);
								return el( CheckboxControl, {
									key: t,
									label: t,
									checked: checked,
									onChange: function(){
										var current = props.attributes.filterTypes || [];
										var next = checked ? current.filter(function(c){ return c!==t; }) : current.concat([t]);
										props.setAttributes({ filterTypes: next });
									}
								});
							})
						),
						el( 'div', { style: { marginTop: '1em' } },
							el( 'strong', null, 'Spalten' ),
							allColumns.map( function ( c ) {
								var checked = columns.includes( c.key );
								return el( 'div', { key: c.key, style: { display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '2px' } },
									el( CheckboxControl, {
										label: c.label,
										checked: checked,
										onChange: function () { toggleColumn( c.key ); }
									} ),
									el( Button, { isSmall: true, onClick: function () { moveColumn( c.key, -1 ); }, disabled: columns.indexOf( c.key ) === 0 }, '↑' ),
									el( Button, { isSmall: true, onClick: function () { moveColumn( c.key, 1 ); }, disabled: columns.indexOf( c.key ) === columns.length - 1 }, '↓' )
								);
							} )
						)
					)
				),
				el( 'div', { className: 'ffami-mission-table-editor-preview' },
					el( 'strong', null, 'Letzte Einsätze (Vorschau Titel-Spalte)' ),
					el( 'div', { style: { fontSize: '11px', opacity: 0.8 } }, 'Einträge: ' + perPage + (props.attributes.pagination ? ' pro Seite' : ' gesamt') ),
					el( 'div', { style: { fontSize: '11px', opacity: 0.8, marginBottom: '4px' } }, 'Spaltenreihenfolge: ' + columns.map( human ).join( ' | ' ) ),
					el( 'ul', null, listItems ),
					el( 'p', null, '(Frontend zeigt vollständige Tabelle)')
				)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
