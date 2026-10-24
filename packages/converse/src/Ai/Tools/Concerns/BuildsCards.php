<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools\Concerns;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use JsonException;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Cards\ActionElement;
use Laravel\Converse\Cards\Actions;
use Laravel\Converse\Cards\Button;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Cards\CardElement;
use Laravel\Converse\Cards\Divider;
use Laravel\Converse\Cards\Field;
use Laravel\Converse\Cards\Fields;
use Laravel\Converse\Cards\Image;
use Laravel\Converse\Cards\LinkButton;
use Laravel\Converse\Cards\RadioSelect;
use Laravel\Converse\Cards\Select;
use Laravel\Converse\Cards\Table;
use Laravel\Converse\Cards\Text;
use Laravel\Converse\Enums\ButtonStyle;
use Laravel\Converse\Exceptions\InvalidCardException;

trait BuildsCards
{
    /**
     * @return array<string, mixed>
     */
    protected function cardSchema(JsonSchema $schema): array
    {
        $option = $schema->object([
            'label' => $schema->string()->required(),
            'value' => $schema->string()->required(),
            'description' => $schema->string()->nullable()->required(),
        ]);

        $action = $schema->object([
            'type' => $schema->string()->enum(array_keys($this->actionBuilders()))->required(),
            'id' => $schema->string()->description('Action ID for button, select and radio_select. Returned to you as action_id when the user clicks. Must not contain [|].')->nullable()->required(),
            'label' => $schema->string()->description('Visible label for button and link_button, optional heading for selects.')->nullable()->required(),
            'value' => $schema->string()->description('Button only. Returned to you as the action value when clicked.')->nullable()->required(),
            'url' => $schema->string()->description('link_button only. Absolute http(s) URL to open.')->nullable()->required(),
            'style' => $schema->string()->enum(array_column(ButtonStyle::cases(), 'value'))->description('Button only.')->nullable()->required(),
            'once' => $schema->boolean()->description('Button only. Disable the button after its first click.')->nullable()->required(),
            'placeholder' => $schema->string()->description('select and radio_select only.')->nullable()->required(),
            'options' => $schema->array()->items($option)->description('select and radio_select only. The chosen option value is returned to you as the action value.')->nullable()->required(),
        ]);

        $element = $schema->object([
            'type' => $schema->string()->enum(array_keys($this->elementBuilders()))->required(),
            'text' => $schema->string()->description('text only. Markdown is rendered for the active platform. Mention a user with <@USER_ID> and a channel with <#CHANNEL_ID>.')->nullable()->required(),
            'url' => $schema->string()->description('image only. Absolute http(s) URL.')->nullable()->required(),
            'alt' => $schema->string()->description('image only.')->nullable()->required(),
            'fields' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
                'value' => $schema->string()->required(),
            ]))->description('fields only. Between 1 and 25 label / value pairs.')->nullable()->required(),
            'headers' => $schema->array()->items($schema->string())->description('table only. Between 1 and 20 column headers.')->nullable()->required(),
            'rows' => $schema->array()->items($schema->array()->items($schema->string()))->description('table only. At most 100 rows, each matching the header count.')->nullable()->required(),
            'caption' => $schema->string()->description('table only.')->nullable()->required(),
            'actions' => $schema->array()->items($action)->description('actions only. Between 1 and 25 controls.')->nullable()->required(),
        ]);

        return [
            'title' => $schema->string()->nullable()->required(),
            'subtitle' => $schema->string()->nullable()->required(),
            'fallback' => $schema->string()->description('Plain text for notifications and surfaces that cannot render the card.')->nullable()->required(),
            'elements' => $schema->array()->items($element)->description('The card body, rendered top to bottom. Fill only the keys that belong to each element type and pass null for the rest.')->required(),
            'raw' => $schema->string()->description('Optional JSON object of native payloads keyed by platform, e.g. {"slack": {"blocks": [...]}, "telegram": {"text": "..."}, "github": {"body": "..."}}. Each listed platform renders its payload instead of the portable elements.')->nullable()->required(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function cardRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:1000'],
            'fallback' => ['nullable', 'string', 'max:1000'],
            'elements' => ['required', 'array', 'min:1', 'max:50'],
            'elements.*.type' => ['required', 'string', 'in:'.implode(',', array_keys($this->elementBuilders()))],
            'elements.*.text' => ['nullable', 'string'],
            'elements.*.url' => ['nullable', 'string'],
            'elements.*.alt' => ['nullable', 'string'],
            'elements.*.caption' => ['nullable', 'string'],
            'elements.*.fields' => ['nullable', 'array'],
            'elements.*.fields.*' => ['array'],
            'elements.*.fields.*.label' => ['required', 'string'],
            'elements.*.fields.*.value' => ['required', 'string'],
            'elements.*.headers' => ['nullable', 'array'],
            'elements.*.headers.*' => ['string'],
            'elements.*.rows' => ['nullable', 'array'],
            'elements.*.rows.*' => ['array'],
            'elements.*.rows.*.*' => ['string'],
            'elements.*.actions' => ['nullable', 'array'],
            'elements.*.actions.*' => ['array'],
            'elements.*.actions.*.type' => ['required', 'string', 'in:'.implode(',', array_keys($this->actionBuilders()))],
            'elements.*.actions.*.id' => ['nullable', 'string'],
            'elements.*.actions.*.label' => ['nullable', 'string'],
            'elements.*.actions.*.value' => ['nullable', 'string'],
            'elements.*.actions.*.url' => ['nullable', 'string'],
            'elements.*.actions.*.style' => ['nullable', 'string', 'in:default,primary,danger'],
            'elements.*.actions.*.once' => ['nullable', 'boolean'],
            'elements.*.actions.*.placeholder' => ['nullable', 'string'],
            'elements.*.actions.*.options' => ['nullable', 'array'],
            'elements.*.actions.*.options.*' => ['array'],
            'elements.*.actions.*.options.*.label' => ['required', 'string'],
            'elements.*.actions.*.options.*.value' => ['required', 'string'],
            'elements.*.actions.*.options.*.description' => ['nullable', 'string'],
            'raw' => ['nullable', 'string', 'max:80000'],
        ];
    }

    protected function card(Request $request): Card|string
    {
        try {
            $card = Card::make($request->filled('title') ? $request->string('title')->value() : null)
                ->withChildren(...array_map($this->element(...), $request->array('elements')));

            if ($request->filled('subtitle')) {
                $card = $card->withSubtitle($request->string('subtitle')->value());
            }

            if ($request->filled('fallback')) {
                $card = $card->withFallback($request->string('fallback')->value());
            }

            if ($request->filled('raw')) {
                foreach ($this->decode($request->string('raw')->value()) as $platform => $payload) {
                    $card = $card->withRaw((string) $platform, $this->payload((string) $platform, $payload));
                }
            }

            $card->validate();

            return $card;
        } catch (InvalidCardException $invalidCardException) {
            return "The card is invalid: {$invalidCardException->getMessage()}";
        }
    }

    /**
     * @return array<string, Closure(array<string, mixed>): CardElement>
     */
    protected function elementBuilders(): array
    {
        return [
            'text' => fn (array $element): CardElement => Text::make((string) ($element['text'] ?? '')),
            'image' => fn (array $element): CardElement => Image::make((string) ($element['url'] ?? ''), $this->optional($element, 'alt')),
            'fields' => fn (array $element): CardElement => Fields::make(...array_map(
                fn (array $field): Field => new Field((string) ($field['label'] ?? ''), (string) ($field['value'] ?? '')),
                $this->list($element, 'fields'),
            )),
            'table' => $this->table(...),
            'divider' => fn (array $element): CardElement => new Divider,
            'actions' => fn (array $element): CardElement => Actions::make(...array_map($this->action(...), $this->list($element, 'actions'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     */
    protected function element(array $element): CardElement
    {
        $builder = $this->elementBuilders()[$element['type']] ?? throw new InvalidCardException("Unsupported card element type [{$element['type']}].");

        return $builder($element);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    protected function table(array $element): Table
    {
        $table = Table::make()->withHeaders(array_map(strval(...), $this->list($element, 'headers')));

        foreach ($this->list($element, 'rows') as $row) {
            $table = $table->row(array_map(strval(...), $row));
        }

        return isset($element['caption']) ? $table->withCaption((string) $element['caption']) : $table;
    }

    /**
     * @return array<string, Closure(array<string, mixed>): ActionElement>
     */
    protected function actionBuilders(): array
    {
        return [
            'button' => fn (array $action): ActionElement => $this->button(Button::make((string) ($action['id'] ?? '')), $action),
            'link_button' => fn (array $action): ActionElement => LinkButton::make((string) ($action['label'] ?? ''), (string) ($action['url'] ?? '')),
            'select' => fn (array $action): ActionElement => $this->select(Select::make((string) ($action['id'] ?? '')), $action),
            'radio_select' => fn (array $action): ActionElement => $this->select(RadioSelect::make((string) ($action['id'] ?? '')), $action),
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function action(array $action): ActionElement
    {
        $builder = $this->actionBuilders()[$action['type'] ?? ''] ?? throw new InvalidCardException('Unsupported card action type ['.($action['type'] ?? 'null').'].');

        return $builder($action);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function button(Button $button, array $definition): Button
    {
        if (($label = $this->optional($definition, 'label')) !== null) {
            $button = $button->withLabel($label);
        }

        if (($value = $this->optional($definition, 'value')) !== null) {
            $button = $button->withValue($value);
        }

        $button = match (ButtonStyle::tryFrom((string) ($definition['style'] ?? '')) ?? ButtonStyle::Default) {
            ButtonStyle::Primary => $button->primary(),
            ButtonStyle::Danger => $button->danger(),
            ButtonStyle::Default => $button,
        };

        return $button->once(($definition['once'] ?? false) === true);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function select(Select $select, array $definition): Select
    {
        if (($label = $this->optional($definition, 'label')) !== null) {
            $select = $select->withLabel($label);
        }

        if (($placeholder = $this->optional($definition, 'placeholder')) !== null) {
            $select = $select->withPlaceholder($placeholder);
        }

        foreach ($this->list($definition, 'options') as $option) {
            $select = $select->option(
                (string) ($option['label'] ?? ''),
                (string) ($option['value'] ?? ''),
                $this->optional($option, 'description'),
            );
        }

        return $select;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(string $json): array
    {
        try {
            $payloads = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidCardException("The raw payload is not valid JSON: {$jsonException->getMessage()}", $jsonException->getCode(), $jsonException);
        }

        return is_array($payloads) && ! array_is_list($payloads) ? $payloads : throw new InvalidCardException('The raw payload must be a JSON object keyed by platform.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(string $platform, mixed $payload): array
    {
        return is_array($payload) && ! array_is_list($payload) ? $payload : throw new InvalidCardException("The raw [{$platform}] payload must be a JSON object.");
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function optional(array $definition, string $key): ?string
    {
        return isset($definition[$key]) ? (string) $definition[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, mixed>
     */
    protected function list(array $definition, string $key): array
    {
        return is_array($definition[$key] ?? null) ? array_values($definition[$key]) : [];
    }
}
