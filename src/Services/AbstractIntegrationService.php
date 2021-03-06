<?php
/**
 * Created by PhpStorm.
 * User: gustavs
 * Date: 30/08/2017
 * Time: 17:29
 */

namespace Solspace\Freeform\Services;

use craft\base\Component;
use craft\db\Query;
use Solspace\Commons\Helpers\PermissionHelper;
use Solspace\Freeform\Events\Integrations\DeleteEvent;
use Solspace\Freeform\Events\Integrations\SaveEvent;
use Solspace\Freeform\Freeform;
use Solspace\Freeform\Library\Configuration\CraftPluginConfiguration;
use Solspace\Freeform\Library\Exceptions\Integrations\IntegrationException;
use Solspace\Freeform\Library\Integrations\AbstractIntegration;
use Solspace\Freeform\Library\Integrations\SettingBlueprint;
use Solspace\Freeform\Models\IntegrationModel;
use Solspace\Freeform\Records\IntegrationRecord;

abstract class AbstractIntegrationService extends Component
{
    const EVENT_BEFORE_SAVE   = 'beforeSave';
    const EVENT_AFTER_SAVE    = 'afterSave';
    const EVENT_BEFORE_DELETE = 'beforeDelete';
    const EVENT_AFTER_DELETE  = 'afterDelete';
    const EVENT_FETCH_TYPES   = 'fetchTypes';

    /**
     * @return IntegrationModel[]
     */
    public function getAllIntegrations(): array
    {
        $results = $this->getQuery()->all();

        $models = [];
        foreach ($results as $result) {
            $models[] = $this->createIntegrationModel($result);
        }

        return $models;
    }

    /**
     * @return AbstractIntegration[]
     */
    public function getAllIntegrationObjects(): array
    {
        $models = $this->getAllIntegrations();

        $integrations = [];
        foreach ($models as $model) {
            $integrations[] = $model->getIntegrationObject();
        }

        return $integrations;
    }

    /**
     * @param int $id
     *
     * @return AbstractIntegration
     * @throws IntegrationException
     * @throws IntegrationException
     */
    public function getIntegrationObjectById($id): AbstractIntegration
    {
        $model = $this->getIntegrationById($id);

        if ($model) {
            return $model->getIntegrationObject();
        }

        throw new IntegrationException(
            Freeform::t('Mailing List integration with ID {id} not found', ['id' => $id])
        );
    }

    /**
     * @param int $id
     *
     * @return IntegrationModel|null
     */
    public function getIntegrationById($id)
    {
        $data = $this->getQuery()->andWhere(['id' => $id])->one();

        if ($data) {
            return $this->createIntegrationModel($data);
        }

        return null;
    }

    /**
     * @param string $handle
     *
     * @return IntegrationModel|null
     */
    public function getIntegrationByHandle(string $handle = null)
    {
        $data = $this->getQuery()->andWhere(['handle' => $handle])->one();

        if ($data) {
            return $this->createIntegrationModel($data);
        }

        return null;
    }

    /**
     * Flag the given mailing list integration so that it's updated the next time it's accessed
     *
     * @param AbstractIntegration $integration
     */
    public function flagIntegrationForUpdating(AbstractIntegration $integration)
    {
        \Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                IntegrationRecord::TABLE,
                ['forceUpdate' => true],
                'id = :id',
                ['id' => $integration->getId()]
            );
    }

    /**
     * @param IntegrationModel $model
     *
     * @return bool
     * @throws \Exception
     */
    public function save(IntegrationModel $model): bool
    {
        $isNew = !$model->id;

        $beforeSaveEvent = new SaveEvent($model, $isNew);
        $this->trigger(self::EVENT_BEFORE_SAVE, $beforeSaveEvent);

        if ($isNew) {
            $record = new IntegrationRecord();
        } else {
            $record = IntegrationRecord::findOne(['id' => $model->id, 'type' => $this->getIntegrationType()]);

            if (!$record) {
                throw new IntegrationException(
                    Freeform::t('Mailing List integration with ID {id} not found', ['id' => $model->id])
                );
            }
        }

        $record->name        = $model->name;
        $record->handle      = $model->handle;
        $record->type        = $this->getIntegrationType();
        $record->class       = $model->class;
        $record->accessToken = $model->accessToken;
        $record->settings    = $model->settings;
        $record->forceUpdate = $model->forceUpdate;
        $record->lastUpdate  = new \DateTime();

        $record->validate();
        $model->addErrors($record->getErrors());

        $configuration = new CraftPluginConfiguration();

        /** @var AbstractIntegration $integrationClass */
        $integrationClass = $record->class;
        foreach ($integrationClass::getSettingBlueprints() as $blueprint) {
            $handle = $blueprint->getHandle();
            if ($blueprint->getType() === SettingBlueprint::TYPE_CONFIG) {
                $value = $configuration->get($handle);

                if (!$value && $blueprint->isRequired()) {
                    $model->addError(
                        'class',
                        Freeform::t(
                            "'{key}' key missing in Freeform's plugin configuration",
                            ['key' => $handle]
                        )
                    );
                }
            } else {
                $value = $model->settings[$handle] ?? null;

                if (!$value && $blueprint->isRequired()) {
                    $model->addError(
                        $integrationClass . $handle,
                        Freeform::t(
                            '{key} is required',
                            ['key' => $blueprint->getLabel()]
                        )
                    );
                }
            }
        }

        if ($beforeSaveEvent->isValid && !$record->hasErrors()) {
            $transaction = \Craft::$app->getDb()->beginTransaction();
            try {
                $record->save(false);

                if ($isNew) {
                    $model->id = $record->id;
                }

                if ($transaction !== null) {
                    $transaction->commit();
                }

                $this->afterSaveHandler($model);

                $this->trigger(self::EVENT_AFTER_SAVE, new SaveEvent($model, $isNew));

                return true;
            } catch (\Exception $e) {
                if ($transaction !== null) {
                    $transaction->rollBack();
                }

                throw $e;
            }
        }

        return false;
    }

    /**
     * @param int $id
     *
     * @return bool
     * @throws \Exception
     */
    public function delete($id)
    {
        PermissionHelper::requirePermission(Freeform::PERMISSION_SETTINGS_ACCESS);

        $model = $this->getIntegrationById($id);
        if (!$model) {
            return false;
        }

        $beforeDeleteEvent = new DeleteEvent($model);
        $this->trigger(self::EVENT_BEFORE_DELETE, $beforeDeleteEvent);

        if (!$beforeDeleteEvent->isValid) {
            return false;
        }

        $transaction = \Craft::$app->getDb()->beginTransaction();
        try {
            $affectedRows = \Craft::$app->getDb()
                ->createCommand()
                ->delete(IntegrationRecord::TABLE, ['id' => $model->id])
                ->execute();

            if ($transaction !== null) {
                $transaction->commit();
            }

            $this->trigger(self::EVENT_AFTER_DELETE, new DeleteEvent($model));

            return (bool) $affectedRows;
        } catch (\Exception $exception) {
            if ($transaction !== null) {
                $transaction->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Perform necessary actions after the integration has been saved
     *
     * @param IntegrationModel $model
     */
    protected function afterSaveHandler(IntegrationModel $model)
    {
    }

    /**
     * Return the integration type
     * MailingList or Crm
     *
     * @return string
     */
    abstract protected function getIntegrationType(): string;

    /**
     * @return Query
     */
    protected function getQuery(): Query
    {
        return (new Query())
            ->select(
                [
                    'integration.id',
                    'integration.name',
                    'integration.handle',
                    'integration.type',
                    'integration.class',
                    'integration.accessToken',
                    'integration.settings',
                    'integration.forceUpdate',
                    'integration.lastUpdate',
                ]
            )
            ->from(IntegrationRecord::TABLE . ' integration')
            ->where(['type' => $this->getIntegrationType()])
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * @param array $data
     *
     * @return IntegrationModel
     */
    protected function createIntegrationModel(array $data): IntegrationModel
    {
        $model = new IntegrationModel($data);

        $model->lastUpdate  = new \DateTime($model->lastUpdate);
        $model->forceUpdate = (bool) $model->forceUpdate;
        $model->settings    = $model->settings ? json_decode($model->settings, true) : [];

        return $model;
    }
}
