<?php

use Rector\Set\ValueObject\LevelSetList;
use PhpCsFixer\Fixer\Operator\ConcatSpaceFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

use PhpCsFixer\Fixer\ArrayNotation\ArraySyntaxFixer;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use Symplify\CodingStandard\Fixer\ArrayNotation\ArrayListItemNewlineFixer;
use Symplify\CodingStandard\Fixer\ArrayNotation\ArrayOpenerAndCloserNewlineFixer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ECSConfig $configurator): void {

  // alternative to CLI arguments, easier to maintain and extend
  $configurator->paths([__DIR__ . '/src', __DIR__ . '/Tests', __DIR__ . '/Transport']);

  // choose 
  $configurator->sets([
    SetList::CLEAN_CODE,
    SetList::COMMON,
    SetList::CONTROL_STRUCTURES,
    SetList::PSR_12,
    // SetList::SYMPLIFY
  ]);

  $configurator->ruleWithConfiguration(ConcatSpaceFixer::class, [
    'spacing' => 'one'
  ]);
  // $configurator->ruleWithConfiguration(NotOperatorWithSuccessorSpaceFixer::class, [

  // ]);

  // indent and tabs/spaces
  // [default: spaces]. BUT: tabs are superiour due to accessibility reasons
  $configurator->indentation('tab');

  $configurator->skip([NotOperatorWithSuccessorSpaceFixer::class]);
};
