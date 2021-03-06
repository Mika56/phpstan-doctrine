<?php declare(strict_types = 1);

namespace PHPStan\Rules\Doctrine\ORM;

use Doctrine\Common\Persistence\ObjectRepository;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\GenericTypeVariableResolver;
use PHPStan\Type\TypeWithClassName;
use PHPStan\Type\VerbosityLevel;

/**
 * @implements Rule<Node\Expr\MethodCall>
 */
class RepositoryMethodCallRule implements Rule
{

	/** @var ObjectMetadataResolver */
	private $objectMetadataResolver;

	public function __construct(ObjectMetadataResolver $objectMetadataResolver)
	{
		$this->objectMetadataResolver = $objectMetadataResolver;
	}

	public function getNodeType(): string
	{
		return Node\Expr\MethodCall::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!isset($node->args[0])) {
			return [];
		}
		$argType = $scope->getType($node->args[0]->value);
		if (!$argType instanceof ConstantArrayType) {
			return [];
		}
		if (count($argType->getKeyTypes()) === 0) {
			return [];
		}
		$calledOnType = $scope->getType($node->var);
		if (!$calledOnType instanceof TypeWithClassName) {
			return [];
		}
		$entityClassType = GenericTypeVariableResolver::getType($calledOnType, ObjectRepository::class, 'TEntityClass');
		if ($entityClassType === null) {
			$entityClassType = GenericTypeVariableResolver::getType($calledOnType, \Doctrine\Persistence\ObjectRepository::class, 'TEntityClass');
			if ($entityClassType === null) {
				return [];
			}
		}
		if (!$entityClassType instanceof TypeWithClassName) {
			return [];
		}
		$entityClass = $entityClassType->getClassName();

		$methodNameIdentifier = $node->name;
		if (!$methodNameIdentifier instanceof Node\Identifier) {
			return [];
		}

		$methodName = $methodNameIdentifier->toString();
		if (!in_array($methodName, [
			'findBy',
			'findOneBy',
			'count',
		], true)) {
			return [];
		}

		$objectManager = $this->objectMetadataResolver->getObjectManagerForClass($entityClass);
		if ($objectManager === null) {
			return [];
		}

		$classMetadata = $objectManager->getClassMetadata($entityClass);

		$messages = [];
		foreach ($argType->getKeyTypes() as $keyType) {
			if (!$keyType instanceof ConstantStringType) {
				continue;
			}

			$fieldName = $keyType->getValue();
			if (
				$classMetadata->hasField($fieldName)
				|| $classMetadata->hasAssociation($fieldName)
			) {
				continue;
			}

			$messages[] = sprintf(
				'Call to method %s::%s() - entity %s does not have a field named $%s.',
				$calledOnType->describe(VerbosityLevel::typeOnly()),
				$methodName,
				$entityClass,
				$fieldName
			);
		}

		return $messages;
	}

}
