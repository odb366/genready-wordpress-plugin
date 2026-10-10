<?php
namespace GenReady\Tests\Unit;

/** The part of WP_REST_Request that the REST callbacks use. */
class FakeRequest {
	/** @var array */
	private $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_params() {
		return $this->params;
	}

	public function get_json_params() {
		return $this->params;
	}
}

/** A complete report as GET /api/v1/reports/:id returns it, with hostile text. */
function report_fixture( array $extra = array() ): array {
	return array_merge(
		array(
			'reportId'      => '11111111-2222-3333-4444-555555555555',
			'url'           => 'https://example.com/',
			'scope'         => 'full',
			'status'        => 'completed',
			'createdAt'     => '2026-10-09T10:00:00.000Z',
			'completedAt'   => '2026-10-09T10:01:00.000Z',
			'scores'        => array(
				'overall'        => 72,
				'content'        => 64,
				'crawlability'   => 88,
				'details'        => array(
					'entity_ratio'   => array(
						'key'         => 'entity_ratio',
						'name'        => 'Facts & Details',
						'description' => 'How many facts the page gives.',
						'category'    => 'content',
						'score'       => 9,
						'verdict'     => 'pass',
					),
					'json_ld_schema' => array(
						'key'         => 'json_ld_schema',
						'name'        => 'Schema Markup',
						'description' => 'Structured data <script>alert(1)</script>',
						'category'    => 'content',
						'score'       => 1,
						'verdict'     => 'fail',
					),
					'robots_txt'     => array(
						'key'         => 'robots_txt',
						'name'        => 'AI Access Rules',
						'description' => 'robots.txt rules for AI crawlers.',
						'category'    => 'crawlability',
						'score'       => 6,
						'verdict'     => 'warn',
					),
					'llm_txt'        => array(
						'key'         => 'llm_txt',
						'name'        => 'LLM File',
						'description' => 'An llms.txt file.',
						'category'    => 'crawlability',
						'score'       => 0,
						'verdict'     => 'fail',
					),
				),
				'agentReadiness' => array(
					'detectedCount' => 2,
					'bonusPoints'   => 3,
					'standards'     => array(
						'webmcp' => array(
							'key'     => 'webmcp',
							'name'    => 'WebMCP',
							'status'  => 'not_detected',
							'message' => 'No WebMCP <script>alert(1)</script> tools found.',
						),
					),
				),
			),
			'fixChecklist'  => array(
				array(
					'severity' => 'high',
					'category' => 'content',
					'metric'   => 'Schema Markup',
					'problem'  => 'No Article/WebPage schema <script>alert(1)</script> found.',
					'fix'      => 'Add JSON-LD.',
				),
			),
			'aiView'        => array(
				'markdown'          => "# Home\n<script>alert(1)</script>\nHello",
				'markdownTruncated' => false,
				'screenshotUrl'     => 'https://genready.ai/screenshots/a.png',
				'jsGap'             => array(
					'gapPercent'           => 42.4,
					'isJavascriptRendered' => true,
					'detectedFramework'    => 'React',
				),
			),
		),
		$extra
	);
}
