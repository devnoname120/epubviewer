PDFJS.Reader.AnnotationLayerController = function (options, reader) {

    this.reader = reader;
    this.annotationDiv = options.annotationDiv;
    this.pdfPage = options.pdfPage;
    this.renderInteractiveForms = options.renderInteractiveForms;
    this.linkService = options.linkService;
    this.downloadManager = options.downloadManager;
    this.annotationLayer = null;

    this.div = null;

    return this;
};

PDFJS.Reader.AnnotationLayerController.prototype.render = function (viewport, intent) {
	var self = this;
	var parameters = {
		intent: (intent === undefined ? 'display' : intent),
	};

	this.pdfPage.getAnnotations(parameters).then(function (annotations) {
		viewport = viewport.clone({ dontFlip: true });
		if (self.annotationLayer) {
			self.annotationLayer.update({ viewport: viewport });
			return;
		}

		// Create an annotation layer div and render only when there is content.
		if (annotations.length === 0) {
			return;
		}

		self.div = self.annotationDiv;
		self.annotationLayer = new PDFJS.AnnotationLayer({
			div: self.div,
			page: self.pdfPage,
			viewport: viewport,
		});

		self.annotationLayer.render({
			annotations: annotations,
			linkService: self.linkService,
			downloadManager: self.downloadManager,
			renderForms: self.renderInteractiveForms,
		});
	});
};

PDFJS.Reader.AnnotationLayerController.prototype.hide = function () {

    if (!this.div) {
        return;
    }

    this.div.setAttribute('hidden', 'true');
};
