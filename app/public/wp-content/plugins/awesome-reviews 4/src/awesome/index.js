import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';   // front-end + editor
import './editor.scss';  // editor-only card

registerBlockType( metadata.name, {
  edit: Edit,
  save: () => null,
} );
