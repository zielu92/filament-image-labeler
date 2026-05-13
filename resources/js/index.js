import { createImageAnnotator } from '@annotorious/annotorious';

import '@annotorious/annotorious/annotorious.css';

window.Annotorious = {
    init: (config) => createImageAnnotator(config.image, config)
};
