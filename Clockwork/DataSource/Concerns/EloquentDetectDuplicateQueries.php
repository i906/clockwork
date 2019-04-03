<?php namespace Clockwork\DataSource\Concerns;

use Clockwork\Helpers\StackTrace;
use Clockwork\Request\Log;
use Clockwork\Request\Request;

use Psr\Log\LogLevel;

trait EloquentDetectDuplicateQueries
{
	protected $duplicateQueries = [];

	protected function appendDuplicateQueriesWarnings(Request $request)
	{
		$log = new Log;

		foreach ($this->duplicateQueries as $query) {
			if ($query['count'] < 1) continue;

			$log->log(
				LogLevel::WARNING,
				"N+1 queries: {$query['model']}::{$query['relation']} loaded {$query['count']} times.",
				[ 'performance' => true, 'trace' => $query['trace'] ]
			);
		}

		$request->log = array_merge($request->log, $log->toArray());
	}

	protected function detectDuplicateQuery(StackTrace $trace)
	{
		$relationFrame = $trace->first(function ($frame) {
			return $frame->function == 'getRelationValue'
				|| $frame->class == \Illuminate\Database\Eloquent\Relations\Relation::class;
		});

		if (! $relationFrame) return;

		if ($relationFrame->class == \Illuminate\Database\Eloquent\Relations\Relation::class) {
			$model = get_class($relationFrame->object->getParent());
			$relation = get_class($relationFrame->object->getRelated());
		} else {
			$model = get_class($relationFrame->object);
			$relation = $relationFrame->args[0];
		}

		$trace = $trace->skip()->limit();

		$hash = implode('-', [ $model, $relation, $trace->first()->file, $trace->first()->line ]);

		if (! isset($this->duplicateQueries[$hash])) {
			$this->duplicateQueries[$hash] = [
				'count'    => 0,
				'model'    => $model,
				'relation' => $relation,
				'trace'    => $trace
			];
		}

		$this->duplicateQueries[$hash]['count']++;
	}
}
