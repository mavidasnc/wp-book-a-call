import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';
import './editor.scss';

// Blocco dinamico: il markup lo produce render.php, quindi save() non restituisce nulla.
registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
