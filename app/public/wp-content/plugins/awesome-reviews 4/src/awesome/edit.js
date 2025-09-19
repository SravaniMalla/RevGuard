import { useEffect, useState } from '@wordpress/element';
import { InspectorControls } from '@wordpress/block-editor';
import {
  PanelBody,
  RangeControl,
  ToggleControl,
  SelectControl,
  TextareaControl,
  Spinner,
  Notice,
} from '@wordpress/components';

import ServerSideRender from '@wordpress/server-side-render';
import './editor.scss';
import './style.scss';

const Edit = ( { attributes, setAttributes } ) => {
  const {
    source,
    count = 12,
    minRating = 1,
    showSource = true,

    widgetMode = 'custom',
    customWidgetId = '',
  } = attributes;

  const [loading, setLoading] = useState(true);
  const [sources, setSources] = useState([]);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    wp.apiFetch({ path: '/awesome-reviews/v1/sources' })
      .then((res) => { if (mounted) { setSources(res || []); setLoading(false);} })
      .catch((e) => { if (mounted) { setError(e?.message || 'Failed to load sources'); setLoading(false);} });
    return () => { mounted = false; };
  }, []);

  const settingsUrl = ( (window?.ajaxurl || '/wp-admin/admin-ajax.php') )
    .replace('admin-ajax.php','admin.php?page=awesome-reviews-settings');

  return (
    <>
      {/* TRUSTINDEX-LIKE EDITOR CARD */}
      <div className="arw-editor-card">
        <div className="arw-ed-header">
          <h2>Southside Eyecare & Optical reviews</h2>
        </div>

        <div className="arw-ed-fields">
          {/* Mode select rendered compact, label visually hidden */}
          <div className="arw-ed-field arw-ed-select">
            <label className="arw-visually-hidden" htmlFor="arw-mode">Mode</label>
            <SelectControl
              id="arw-mode"
              label=""
              value={ widgetMode }
              options={[
                { label: 'Custom widget id', value: 'custom' },
                { label: 'Saved Source (API)', value: 'saved' },
              ]}
              onChange={(v)=> setAttributes({ widgetMode: v })}
            />
          </div>

          {/* Big textarea below the select (only for custom mode) */}
          { widgetMode === 'custom' && (
            <div className="arw-ed-field arw-ed-textarea">
              <label className="arw-visually-hidden" htmlFor="arw-custom-id">Custom widget id</label>
              <TextareaControl
                id="arw-custom-id"
                label=""
                placeholder=""
                value={ customWidgetId }
                onChange={(v)=> setAttributes({ customWidgetId: v }) }
              />
            </div>
          )}

          {/* Saved Source picker (only when using API mode) */}
          { widgetMode === 'saved' && (
            <div className="arw-ed-field">
              { error && <Notice status="error" isDismissible={false}>{ error }</Notice> }
              { loading ? <Spinner/> : (
                <SelectControl
                  label="Saved Source"
                  value={ source || '' }
                  options={[
                    { label: '— Select —', value: '' },
                    ...sources.map(s => ({ label: `${s.label} (${s.provider})`, value: s.id }))
                  ]}
                  onChange={(val)=> setAttributes({ source: val })}
                />
              ) }
            </div>
          )}

          <div className="arw-ed-foot">
            If you have a Trustindex account, connect it to access the widgets in your account as well.{' '}
            <a href={ settingsUrl } target="_blank" rel="noopener noreferrer">Connect account</a>
          </div>
        </div>
      </div>

      {/* SIDEBAR CONTROLS (for API mode) */}
      <InspectorControls>
        <PanelBody title="Display">
          <RangeControl
            label="Maximum reviews to show"
            value={ count }
            onChange={(v) => setAttributes({ count: v })}
            min={1}
            max={24}
          />
          <RangeControl
            label="Minimum rating"
            help="Only show reviews with this many stars or higher."
            value={ minRating }
            onChange={(v) => setAttributes({ minRating: v })}
            min={1}
            max={5}
          />
          <ToggleControl
            label="Show provider badge"
            checked={ !!showSource }
            onChange={(v) => setAttributes({ showSource: !!v })}
          />
        </PanelBody>
      </InspectorControls>

      {/* Live preview when using Saved Source (API) */}
      { widgetMode === 'saved' && (
        <div className="awesome-reviews-editor">
          <ServerSideRender
            block="awesome/reviews"
            attributes={{ ...attributes }}
          />
        </div>
      )}
    </>
  );
};

export default Edit;
